<?php

use Slim\Http\Request;
use Slim\Http\Response;
use GuzzleHttp\Client;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\Cookie;
use Dflydev\FigCookies\SetCookie;

require_once('plunderingfunctions.php');
// Routes


$app->get('/', function (Request $request, Response $response, array $args) {
    return $this->renderer->render($response, 'index.phtml', $args);
});


$app->get('/owner/{ownerid}', function (Request $request, Response $response, array $args) {
    return $this->renderer->render($response, 'owner.phtml', $args);
});


$app->get('/addminion', function (Request $request, Response $response, array $args) {
    include('config.php');
    $state=hash('sha256', microtime(true).rand().$_SERVER['REMOTE_ADDR']);
    $this->session->set('state', $state);
    return $response->withRedirect(
        'https://login.eveonline.com/'.
        'oauth/authorize'.
        '?response_type=code'.
        '&redirect_uri='.urlencode($plundering_config['callback']).
        '&client_id='.$plundering_config['client_id'].
        '&scope=esi-industry.read_character_mining.v1'.
        '&state='.$state
    );

});


$app->get('/login', function (Request $request, Response $response, array $args) {
    $allGetVars = $request->getQueryParams();
    include('config.php');

    if (isset($allGetVars['state'])) {
        $state=$this->session->get('state');
        if ($state != $allGetVars['state']) {
            die("bad state");
        }
        $client = new Client();

        $headers=[
            'Authorization'=>'Basic '.base64_encode($plundering_config['client_id'].':'.$plundering_config['secret_key']),
            'User-Agent'=>$plundering_config['useragent']
        ];
        $body=['grant_type'=>'authorization_code','code'=>$allGetVars['code']];
        $loginresponse = $client->request('POST', 'https://login.eveonline.com/oauth/token', ['form_params'=>$body, 'headers'=>$headers]);
        if ($loginresponse->getStatusCode() != 200) {
            die('Bad state');
        }
        $json=json_decode($loginresponse->getBody());
        $access_token=$json->access_token;
        $refresh_token='';
        if (isset($json->refresh_token)) {
            $refresh_token=$json->refresh_token;
        } else {
            $refresh_token=-1;
        }

        $headers=[
            'Authorization'=>'Bearer '.$access_token,
            'User-Agent'=>$plundering_config['useragent']
        ];

        $verifyresponse = $client->request('GET', 'https://login.eveonline.com/oauth/verify', ['headers'=>$headers]);
        if ($verifyresponse->getStatusCode() != 200) {
            die('Bad state');
        }

        $json=json_decode($verifyresponse->getBody());
        $characterid=$json->CharacterID;
        $charactername=$json->CharacterName;
        $characterownerhash=$json->CharacterOwnerHash;

        $dbh = new PDO($plundering_config['databaseconnection']);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

        if (($refresh_token!=-1) && ($this->session->get('loggedin')==true)) {
            // user is already logged in, and this is an addition of a character
            $ownerid=$this->session->get('ownerid');
            addMinion($ownerid, $refresh_token, $characterid, $dbh);
            return $response->withRedirect('/owner/'.$ownerid);
        } else {
            // User isn't logged in.
            $this->session->set('loggedin', true);
            $nonce=hash('sha256', microtime(true).rand().$_SERVER['REMOTE_ADDR']);
            $this->session->set('nonce', $nonce);
            if (ownerExists($characterid, $characterownerhash, $dbh)) {
                $ownerid=ownerID($characterid, $characterownerhash, $dbh);
            } else {
                $ownerid=registerOwner($characterid, $characterownerhash, $dbh);
            }
            $this->session->set('ownerid', $ownerid);
            $response = FigResponseCookies::set($response, SetCookie::create('owner')->withValue($ownerid));
            $response = FigResponseCookies::set($response, SetCookie::create('nonce')->withValue($nonce));
            return $response->withRedirect('/owner/'.$ownerid);
        }

    } else {
        $state=hash('sha256', microtime(true).rand().$_SERVER['REMOTE_ADDR']);
        $this->session->set('state', $state);
        return $response->withRedirect(
            'https://login.eveonline.com/'.
            'oauth/authorize'.
            '?response_type=code'.
            '&redirect_uri='.urlencode($plundering_config['callback']).
            '&client_id='.$plundering_config['client_id'].
            '&state='.$state
        );
    }
});


$app->get('/api/ownerlist', function (Request $request, Response $response, array $args) {
    include('config.php');


    $nonce = $request->getHeader('NONCE');
    if (isset($nonce[0])) {
        $nonce=$nonce[0];
    } else {
        return false;
    }

    if (!isset($nonce)) {
        return false;
    }

    if ($nonce != $this->session->get('nonce')) {
        error_log('nonce mismatch');
        return false;
    }

    $dbh = new PDO($plundering_config['databaseconnection']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $ownerid=$this->session->get('ownerid');

    $ownersql="select userid from users where hashed=:identifier";
    $stmt = $dbh->prepare($ownersql);
    $stmt->execute(array(":identifier"=>$ownerid));
    $userrow=$stmt->fetch(PDO::FETCH_ASSOC);
    $userid=$userrow['userid'];

    $charactersql="select internalID,characterid,hashed from character where owner=:owner";

    $stmt = $dbh->prepare($charactersql);
    $stmt->execute(array(":owner"=>$userid));
    $characters=array();

    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        array_push($characters, array('id'=>$row['internalid'],'characterid'=>$row['characterid'],'name'=>$row['hashed']));
    }

    $data=array('minions'=>$characters);
    $response = $response->withJson($data);
    return $response;
});

$app->get('/api/ownerdata/{owner}', function (Request $request, Response $response, array $args) {
    include('config.php');


    $dbh = new PDO($plundering_config['databaseconnection']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

    $minersql="select character.identifier from users join character on userid=character.owner where users.hashed=:identifier";
    $stmt = $dbh->prepare($minersql);
    $stmt->execute(array(":identifier"=>$args['owner']));


    $characters=[];
    while ($minerrow=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $characters[]=$minerrow['identifier'];
    }

    $miningdata=array();
    foreach ($characters as $character) {
        $data=[];
        $minesql="select miningdate,typeid,quantity,value from ore join character on miner=character.internalid where hashed=:identifier";
        $stmt = $dbh->prepare($minesql);
        $stmt->execute(array(":identifier"=>$character));
        while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            array_push($data, array('id'=>$row['miningdate'],'typeid'=>$row['typeid'],'quantity'=>$row['quantity'],'value'=>$row['value']));
        }
        $miningdata[]=$data;
    }

    $totalsql=<<<EOS
        select miningdate,typeid,sum(quantity) as quantity,sum(value) as value
        from ore 
        join character on miner=character.internalid 
        join users on userid=character.owner 
        where users.hashed=:identifier
        group by miningdate,typeid
EOS;

    $stmt = $dbh->prepare($totalsql);
    $stmt->execute(array(":identifier"=>$args['owner']));
    $totaldata=[];
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
         array_push($totaldata, array('id'=>$row['miningdate'],'typeid'=>$row['typeid'],'quantity'=>$row['quantity'],'value'=>$row['value']));
    }




    $data=array('miners'=>$miningdata,"total"=>$totaldata);
    $response = $response->withJson($data);
    return $response;
});

$app->get('/api/frontpage', function (Request $request, Response $response, array $args) {
    include('config.php');


    $dbh = new PDO($plundering_config['databaseconnection']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

    $totalsql=<<<EOS
        select typeid,sum(quantity) as quantity,sum(value) as value
        from ore
        where miningdate>= now() - interval '1 month'
        group by typeid
EOS;

    $stmt = $dbh->prepare($totalsql);
    $stmt->execute(array());
    $totaldata=[];
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
         array_push($totaldata, array('typeid'=>$row['typeid'],'quantity'=>$row['quantity'],'value'=>$row['value']));
    }

    
    $bestminersql=<<<EOS
        select users.identifier,sum(value) as value
        from ore 
        join character on ore.miner=character.internalid 
        join users on character.owner=users.userid
        where miningdate>= now() - interval '1 month'
        group by users.identifier
        order by sum(value) desc
        limit 10
EOS;

    $stmt = $dbh->prepare($bestminersql);
    $stmt->execute(array());
    $minerdata=[];
    while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
         array_push($minerdata, array('id'=>$row['identifier'],'value'=>$row['value']));
    }


    $data=array("total"=>$totaldata,"miners"=>$minerdata);
    $response = $response->withJson($data);
    return $response;
});
