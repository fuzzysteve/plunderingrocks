<?php

function ownerExists($characterid, $characterownerhash, $dbh)
{

    $sql="select 1 from users where characterid=:characterid and characterownerhash=:characterownerhash";

    $stmt = $dbh->prepare($sql);
    $stmt->execute(array(":characterid"=>$characterid, ":characterownerhash"=>$characterownerhash));

    if ($stmt->rowCount()) {
        return true;
    }
    return false;
}

function ownerID($characterid, $characterownerhash, $dbh)
{

    $sql="select hashed from users where characterid=:characterid and characterownerhash=:characterownerhash";

    $stmt = $dbh->prepare($sql);
    $stmt->execute(array(":characterid"=>$characterid, ":characterownerhash"=>$characterownerhash));

    $owner=$stmt->fetch(PDO::FETCH_ASSOC);

    return $owner['hashed'];

}


function addMinion($ownerid, $refresh_token, $characterid, $dbh)
{

    $namesql="select random_name()";

    $namestmt = $dbh->prepare($namesql);

    $namestmt->execute();
    $name=$namestmt->fetch(PDO::FETCH_ASSOC);
    $newhash=$name['random_name'];

    while (1) {
        $sql="select 1 from character where hashed=:hashed";
        $stmt = $dbh->prepare($sql);
        $stmt->execute(array(":hashed"=>$newhash));
        if ($stmt->rowCount()==0) {
            break;
        }
        $position+=1;
        $namestmt->execute();
        $name=$namestmt->fetch(PDO::FETCH_ASSOC);
        $newhash=$name['random_name'];
        if ($position>10) {
            return -1;
        }
    }


    $ownersql="select userid from users where hashed=:identifier";
    $stmt = $dbh->prepare($ownersql);
    $stmt->execute(array(":identifier"=>$ownerid));
    $userrow=$stmt->fetch(PDO::FETCH_ASSOC);
    $userid=$userrow['userid'];
    $sql="insert into character (owner,anonymous,identifier,hashed,refresh_token,characterid)
        values
        (:owner,true,:hashed,:hashed,:refreshtoken,:characterid)
        ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute(array(
        ":owner"=>$userid,
        ":hashed"=>$newhash,
        ":refreshtoken"=>$refresh_token,
        ":characterid"=>$characterid
    ));
}

function registerOwner($characterid, $characterownerhash, $dbh)
{

    $namesql="select random_name()";

    $namestmt = $dbh->prepare($namesql);

    $namestmt->execute();
    $name=$namestmt->fetch(PDO::FETCH_ASSOC);
    $newhash=$name['random_name'];

    while (1) {
        $sql="select 1 from users where hashed=:hashed";
        $stmt = $dbh->prepare($sql);
        $stmt->execute(array(":hashed"=>$newhash));
        if ($stmt->rowCount()==0) {
            break;
        }
        $position+=1;
        $namestmt->execute();
        $name=$namestmt->fetch(PDO::FETCH_ASSOC);
        $newhash=$name['random_name'];
        if ($position>10) {
            return -1;
        }
    }

    $sql="insert into users (hashed,anonymous,characterownerhash,characterid,identifier)
        values
        (:hashed,true,:characterownerhash,:characterid,:hashed)
        ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute(array(
        ":hashed"=>$newhash,
        ":characterownerhash"=>$characterownerhash,
        ":characterid"=>$characterid
    ));

    return $newhash;
}
