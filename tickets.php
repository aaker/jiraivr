<?php
require "creds.php";
session_start();
header("Content-Type: text/xml");

echo '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>';

function gather($digits,$action,$audio)
{
  echo "<Gather numDigits='$digits' action='$action'>";
  echo "<Play>$audio</Play>";
  echo "</Gather>";
}

function play($action,$audio)
{
  echo "<Play action='$action'>$audio</Play>";
}

function forward($location)
{
  echo "<Forward >$location</Forward>";
}

function jira($app, $tix)
{
  global $jira_hostname;
  global $jira_username;
  global $jira_password;
  //https://developer.atlassian.com/cloud/jira/platform/integrating-with-jira-cloud/
  //https://developer.atlassian.com/cloud/jira/platform/jira-rest-api-basic-authentication/

  $release_notes_id = "customfield_10505";
  $fixVersions = "fixVersions";

  $url = "https://$jira_hostname/rest/api/2/issue/";
  switch ($app) {
      case 1:
          $url .= "API-";
          break;
      case 2:
          $url .= "OMP-";
          break;
      case 3:
          $url .= "NMS-";
          break;
  }
  $url .= $tix;
  $ch = curl_init($url);

  $headers = array('Content-Type:application/json');
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
  curl_setopt($ch, CURLOPT_USERPWD, $jira_username . ":" . $jira_password);

  $json = curl_exec($ch);
  if ($json!= null)   // Convert JSON to PHP object
    $phpObj =  json_decode($json,true);

  $speech = "<speak>";

  if (!isset($phpObj) || !isset($phpObj['key']))
  {
    return "We are unable to locate your ticket. ";
  }

  $speech .="Ticket ".substr($phpObj['key'],0,4) ."<say-as interpret-as=\"digits\">".substr($phpObj['key'],4)."</say-as>" .
   " a ticket with type ". $phpObj['fields']['issuetype']['name']. " with the title of '".
   $phpObj['fields']['summary']."' is in status '".$phpObj['fields']['status']['name']."'.";

  if ($phpObj['fields']['status']['name']== "Released"){
    foreach ($phpObj['fields']['fixVersions'] as &$value)
        $speech .= "<p>This ticket has been released in version ". $value['name']."</p>";
  }
  else if ($phpObj['fields']['fixVersions'])
  {
    $speech .= " <p>This ticket will be in a upcoming release. Version or versions will be ";
    foreach ($phpObj['fields']['fixVersions'] as &$value) {
      $speech .= $value['name'].", ";
    }
    $speech .= " </p>";
  }
  $give_release_notes = false;
  if ($phpObj['fields']['status']['name']== "Testing Complete"
      || $phpObj['fields']['status']['name']== "Ready for Testing"
      || $phpObj['fields']['status']['name']== "Done"
      || $phpObj['fields']['status']['name']== "Pending Merge"
      || $phpObj['fields']['status']['name']== "In Testing"
      || $phpObj['fields']['status']['name']== "Released"
  )
    $give_release_notes=true;

  if ($give_release_notes && isset($phpObj['fields']['customfield_10505'] ))
  {
    if (isset($phpObj['fields']['customfield_11100']) && isset($phpObj['fields']['customfield_11100']['value'])
          && $phpObj['fields']['customfield_11100']['value']=="Yes"  )
      $speech .= "<p>This ticket has been marked internal so no further information is available</p>" ;
    else {
      $speech .= "<p>The release notes for this ticker are as follows</p> " . $phpObj['fields']['customfield_10505']."";
    }
  }
  $speech .= "</speak>";
  return  $speech;
}

function awsSpeech($speech)
{
    global $aws_token;
    global $aws_key;

    require 'vendor/autoload.php';
    $s3 = new Aws\Polly\PollyClient([
      'version'     => 'latest',
      'region'      => 'us-west-2',
      'credentials' => [
        'key'    => $aws_token,
        'secret' => $aws_key
      ]
    ]);

    $result = $s3->synthesizeSpeech([
        'LexiconNames' => [],
        'OutputFormat' => 'mp3',
        'SampleRate' => '8000',
        'Text' => $speech,
        'TextType' => "ssml",
        'VoiceId' => 'Joanna',
    ]);


    $tmpName = "polly".uniqid();
    file_put_contents("/tmp/".$tmpName.".mp3",
      $result['AudioStream']->getContents() );
    $cmd1 = '/usr/bin/mpg123 -w '."/tmp/".$tmpName.
      '.wav '."/tmp/".$tmpName.'.mp3';
    $cmd2 = '/usr/bin/sox '."/tmp/".$tmpName.'.wav '.
      ' -e mu-law -r 8000 -c 1 -b 8 '."audio/".$tmpName.".wav";
    exec($cmd1);
    exec($cmd2);
    return "audio/".$tmpName.".wav";
}



if (!isset($_REQUEST["case"])) {
  $speech = "<speak>Thank you for calling the netsapiens automated ticket status system. ";
  $speech .= "For API tickets press 1. For Portal tickets press 2. For NMS or core tickets press 3</speak>";
  gather(1,"tickets.php?case=requestNumber",awsSpeech($speech));
}
else if ($_REQUEST["case"] == "requestNumber") {
  $speech = "<speak>Please enter the ticket number.</speak>";
  $_SESSION["project"]=$_REQUEST["Digits"];
  gather(4,"tickets.php?case=playStatus",awsSpeech($speech));
}
else if ($_REQUEST["case"] == "playStatus") {
  $speech = jira($_SESSION["project"],$_REQUEST["Digits"]);
  $audioPath = awsSpeech($speech);
  play("tickets.php",$audioPath);
}
