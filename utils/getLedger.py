import requests
from sqlalchemy import create_engine,Table,MetaData,text
from requests_futures.sessions import FuturesSession
import requests_futures
from concurrent.futures import as_completed
import base64
import datetime
import redis
import pprint


class Character:
    def __init__(self):
        pass


def authFuture(requestsConnection,characterid,refresh_token,internalid,max_date,config):
    headers = {'Authorization':'Basic '+ base64.b64encode(config['clientid']+':'+config['secret']),'User-Agent':config['useragent']}
    query = {'grant_type':'refresh_token','refresh_token':refresh_token}
    future = requestsConnection.post('https://login.eveonline.com/oauth/token',params=query,headers=headers)
    future.internalid=internalid
    future.characterid=characterid
    future.max_date=max_date
    return future


def process_oauth(result):
        r=result.result()
        if r.status_code == 200:
            response = r.json()
            accesstoken = response['access_token']
            character=Character()
            character.accesstoken=accesstoken
            character.internalid=result.internalid
            character.characterid=result.characterid
            character.max_date=result.max_date
            character.page=1
            return character
        else:
            return result.characterid

def get_page(requestsConnection,character,config):
    headers = {'Authorization':'Bearer '+ character.accesstoken,'User-Agent':config['useragent']}
    query = {'page':character.page}
    future = requestsConnection.get('https://esi.tech.ccp.is/latest/characters/{}/mining/'.format(character.characterid),params=query,headers=headers)
    future.accesstoken=character.accesstoken
    future.internalid=character.internalid
    future.characterid=character.characterid
    future.max_date=character.max_date
    future.page=character.page
    return future

def process_page(result):
    r=result.result()
    returnval={}
    if r.status_code == 200:
        response = r.json()
        character=Character()
        character.accesstoken=result.accesstoken
        character.internalid=result.internalid
        character.characterid=result.characterid
        character.max_date=result.max_date
        character.page=result.page+1
        if r.headers['x-pages']<result.page:
            returnval['nextpage']=character
        miningdata=[]
        today=datetime.date.today()
        for mining_entry in response:
            miningdate=datetime.datetime.date(datetime.datetime.strptime(mining_entry['date'],"%Y-%m-%d"))
            if miningdate>result.max_date and miningdate<today:
                miningdata.append([result.internalid,miningdate,mining_entry['type_id'],mining_entry['quantity']])
        returnval['data']=miningdata
    return returnval



import ConfigParser, os
fileLocation = os.path.dirname(os.path.realpath(__file__))
inifile=fileLocation+'/esi.cfg'

config = ConfigParser.ConfigParser()
config.read(inifile)

oauth_settings={}
oauth_settings['clientid']=config.get('oauth','clientid')
oauth_settings['secret']=config.get('oauth','secret')
reqs_num_workers=config.getint('requests','max_workers')
oauth_settings['useragent']=config.get('requests','useragent')

postgresconnection=config.get('database','connection')

engine = create_engine(postgresconnection)
connection = engine.connect()

metadata = MetaData()

character_table=Table("character",metadata,autoload=True, autoload_with=engine)
ore_table=Table("ore",metadata,autoload=True, autoload_with=engine)

dbresult=connection.execute("select character.internalid,characterid,refresh_token,coalesce(max(miningdate),'1970-01-01') max_date from character left join ore on character.internalid=ore.miner where active=true group by  character.internalid,characterid,refresh_token")

futures=[]
session = FuturesSession(max_workers=reqs_num_workers)
session.headers.update({'UserAgent': oauth_settings['useragent']});

batch_count=1;

row=dbresult.fetchone()
miner_data=[]
badcharacters=[]
while row is not None:
    while (batch_count<11 and row is not None):
        futures.append(authFuture(session,row['characterid'],row['refresh_token'],row['internalid'],row['max_date'],oauth_settings))
        row=dbresult.fetchone()
        batch_count+=1
    batch_count=1
    characters=[]
    for result in as_completed(futures):
        character=process_oauth(result)
        if isinstance(character,Character):
            characters.append(process_oauth(result))
        else:
            badcharacters.append(character)
    futures=[]
    while True:
        # break when all pages are handled.
        nextpages=[]
        characterpages=[]
        for character in characters:
            characterpages.append(get_page(session,character,oauth_settings))
        for characterpage in as_completed(characterpages):
            processedpage=process_page(characterpage)
            if processedpage.get('nextpage',None) is not None:
                nextpages.append(processedpage['nextpage'])
            miner_data.extend(processedpage['data'])
        characters=nextpages
        if len(characters)==0:
            # No more pages to acquire for these characters
            break


# insert values into database


oreresult=connection.execute('select "typeID","portionSize" from evesde."invTypes" ore join evesde."invGroups" groups on ore."groupID"=groups."groupID" where "categoryID"=25  and "typeName" not like \'%%compressed%%\'')


materialvaluesresult=connection.execute('select distinct "materialTypeID" from evesde."invTypeMaterials" where "typeID" in (select "typeID" from evesde."invTypes" ore join evesde."invGroups" groups on ore."groupID"=groups."groupID" where "categoryID"=25)')

basevalues={}
orevalues={}

rediscon = redis.StrictRedis(host='localhost', port=6379, db=0)

for row in materialvaluesresult.fetchall():
    sell=rediscon.get('forgesell-{}'.format(row['materialTypeID']))
    if sell is not None:
        basevalues[row['materialTypeID']]=float(rediscon.get('forgesell-{}'.format(row['materialTypeID'])).split("|")[0])
    else:
        basevalues[row['materialTypeID']]=0



for row in oreresult.fetchall():
    materialsresult=connection.execute(text('select "materialTypeID","quantity" from evesde."invTypeMaterials" itm where itm."typeID"=:typeid'),typeid=row['typeID'])
    value=0
    for material in materialsresult.fetchall():
        value+=basevalues[material['materialTypeID']]*material['quantity']
    orevalues[row['typeID']]=value/row['portionSize']


for mined in miner_data:
    connection.execute(ore_table.insert(),miner=mined[0],miningdate=mined[1],typeid=mined[2],quantity=mined[3],value=int(orevalues[mined[2]]*mined[3]*100))


# Take out Characters which don't authenticate
for badcharacter in badcharacters:
    connection.execute(character_table.update().where(character_table.c.characterid==badcharacter).values(active=False))
