<?
   require( "dbConn.class" );
   $dbConnection = new dbConn();
   $mysqli = $dbConnection->getDatabaseConnection();

   $currentTime = time();
   $playerId = (int) $_REQUEST["id"];
   $debug = false;
   $setCheckpoint = false;
   if( isset( $_REQUEST["debug"] )) $debug = true;
   if( isset( $_REQUEST["checkpoint"] )) $setCheckpoint = true;
   $mystats = new stdClass;

   $sql = "select if( exists( select 1 from wotstats where playerid = {$playerId} ), ( select cachedate from wotstats where playerid = {$playerId} order by cachedate desc limit 1 ), ( select 0 )) as cachedate";
   $result = $mysqli->query( $sql );
   $lastApiHit = $result->fetch_assoc();
   $lastApiHit = (int) $lastApiHit["cachedate"];
   $hitApi = false;

   if(( $currentTime >= ( $lastApiHit + 3600 )) || $debug ) {
      // only hit api once per hour
      $hitApi = true;
      $apiStats = file_get_contents( "http://api.worldoftanks.com/community/accounts/{$playerId}/api/1.9/?source_token=WG-WoT_Assistant-1.4.1" );
      cacheApiData( $cacheFile, $playerId, $currentTime );
      $statsObject = json_decode( $apiStats, false );
      $mystats->current->statsdate  = $statsObject->data->updated_at;
      $mystats->current->battles    = $statsObject->data->summary->battles_count;
      $mystats->current->victories  = $statsObject->data->summary->wins;
      $mystats->current->detections = $statsObject->data->battles->spotted;
      $mystats->current->defense    = $statsObject->data->battles->dropped_capture_points;
      $mystats->current->kills      = $statsObject->data->battles->frags;
      $mystats->current->damage     = $statsObject->data->battles->damage_dealt;
      foreach( $statsObject->data->vehicles as $vehicle ) {
         $battlesAtTier[$vehicle->level] += $vehicle->battle_count;
         $weightedBattles += $vehicle->battle_count * $vehicle->level;

         $tank = new stdClass;
         $tank->tankname = $vehicle->localized_name;
         $tank->tier = $vehicle->level;
         $tank->battles = $vehicle->battle_count;
         $tank->victories = $vehicle->win_count;
         $tank->winrate = round(( $vehicle->win_count / $vehicle->battle_count * 100 ), 2 );
         $mystats->current->tanks[$tank->tankname] = clone $tank;
      }
      $mystats->current->weightedBattles = $weightedBattles;
      $mystats->current->avgtier    = $mystats->current->weightedBattles / $mystats->current->battles;

      $checkpoint = 0;
      if(( $currentTime - $statsObject->data->updated_at ) >= 21600 ) $checkpoint = 1;
      $sql = "insert into wotstats ( playerid, cachedate, statsdate, battles, victories, detections, defense, kills, damage, checkpoint ) values ( {$playerId}, '{$currentTime}', '{$mystats->current->statsdate}', {$mystats->current->battles}, {$mystats->current->victories}, {$mystats->current->detections}, {$mystats->current->defense}, {$mystats->current->kills}, {$mystats->current->damage}, {$checkpoint} )";
      $mysqli->query( $sql );
      $newStatId = $mysqli->insert_id;

      $sql = "insert into wotstats_tanks ( statid, tankname, tier, battles, victories ) values ";
      $comma = "";
      foreach( $mystats->current->tanks as $tankname => $tank ) {
         $sql .= "{$comma} ( {$newStatId}, '{$tank->tankname}', {$tank->tier}, {$tank->battles}, {$tank->victories} )";
         $comma = ",";
      }
      $mysqli->query( $sql );
   } else {
      // load cached data instead
      $result = $mysqli->query( "select * from wotstats where playerid = {$playerId} order by cachedate desc limit 1" );
      $ls = $result->fetch_assoc();
      $mystats->current->statsdate  = (int) $ls["statsdate"];
      $mystats->current->battles    = (int) $ls["battles"];
      $mystats->current->victories  = (int) $ls["victories"];
      $mystats->current->detections = (int) $ls["detections"];
      $mystats->current->defense    = (int) $ls["defense"];
      $mystats->current->kills      = (int) $ls["kills"];
      $mystats->current->damage     = (int) $ls["damage"];
      $mystats->current->avgtier    = (float) $ls["avgtier"];

      $result = $mysqli->query( "select * from wotstats_tanks where statid = {$ls["id"]} " );
      while( $vehicle = $result->fetch_assoc() ) {
         $battlesAtTier[$vehicle["tier"]] += $vehicle["tier"];
         $weightedBattles += $vehicle["battles"] * $vehicle["tier"];

         $tank = new stdClass;
         $tank->tankname = $vehicle["tankname"];
         $tank->tier = $vehicle["tier"];
         $tank->battles = $vehicle["battles"];
         $tank->victories = $vehicle["victories"];
         $tank->winrate = round(( $vehicle["victories"] / $vehicle["battles"] * 100 ), 2 );
         $mystats->current->tanks[$tank->tankname] = clone $tank;
      }
      $mystats->current->weightedBattles = $weightedBattles;
      $mystats->current->avgtier    = $mystats->current->weightedBattles / $mystats->current->battles;

   }
   $mystats->current->winrate       = $mystats->current->victories / $mystats->current->battles * 100;
   $mystats->current->avgkills      = $mystats->current->kills / $mystats->current->battles;
   $mystats->current->avgdamage     = $mystats->current->damage / $mystats->current->battles;
   $mystats->current->avgdetections = $mystats->current->detections / $mystats->current->battles;
   $mystats->current->avgdefense    = $mystats->current->defense / $mystats->current->battles;
   $mystats->current->wn7           = calculateWN7( $mystats->current->battles, $mystats->current->winrate, $mystats->current->avgdetections, $mystats->current->avgdefense, $mystats->current->avgkills, $mystats->current->avgdamage, $mystats->current->avgtier );

   // load last checkpoint
   $hasCheckpoint = false;
   $result = $mysqli->query( "select count(id) as checkpoints from wotstats where playerid = {$playerId} and checkpoint = 1" );
   $checkpoints = $result->fetch_assoc();
   if( (int) $checkpoints["checkpoints"] > 0 ) $hasCheckpoint = true;

   if( $hasCheckpoint ) {
      $result = $mysqli->query( "select * from wotstats where playerid = {$playerId} and checkpoint = 1 order by cachedate desc limit 1" );
      $cp = $result->fetch_assoc();
      $mystats->checkpoint->statsdate     = $cp["statsdate"];
      $mystats->checkpoint->battles       = $cp["battles"];
      $mystats->checkpoint->victories     = $cp["victories"];
      $mystats->checkpoint->detections    = $cp["detections"];
      $mystats->checkpoint->defense       = $cp["defense"];
      $mystats->checkpoint->kills         = $cp["kills"];
      $mystats->checkpoint->damage        = $cp["damage"];
      $weightedBattles = 0;
      $result = $mysqli->query( "select * from wotstats_tanks where statid = {$cp["id"]} " );
      while( $vehicle = $result->fetch_assoc() ) {
         $battlesAtTier[$vehicle["tier"]] += $vehicle["tier"];
         $weightedBattles += $vehicle["battles"] * $vehicle["tier"];

         $tank = new stdClass;
         $tank->tankname = $vehicle["tankname"];
         $tank->tier = $vehicle["tier"];
         $tank->battles = $vehicle["battles"];
         $tank->victories = $vehicle["victories"];
         $tank->winrate = round(( $vehicle["victories"] / $vehicle["battles"] * 100 ), 2 );
         $mystats->checkpoint->tanks[$tank->tankname] = clone $tank;
      }
      $mystats->checkpoint->weightedBattles = $weightedBattles;
      $mystats->checkpoint->avgtier       = $mystats->checkpoint->weightedBattles / $mystats->checkpoint->battles;
      $mystats->checkpoint->winrate       = $mystats->checkpoint->victories / $mystats->checkpoint->battles * 100;
      $mystats->checkpoint->avgkills      = $mystats->checkpoint->kills / $mystats->checkpoint->battles;
      $mystats->checkpoint->avgdamage     = $mystats->checkpoint->damage / $mystats->checkpoint->battles;
      $mystats->checkpoint->avgdetections = $mystats->checkpoint->detections / $mystats->checkpoint->battles;
      $mystats->checkpoint->avgdefense    = $mystats->checkpoint->defense / $mystats->checkpoint->battles;
      $mystats->checkpoint->wn7           = calculateWN7( $mystats->checkpoint->battles, $mystats->checkpoint->winrate, $mystats->checkpoint->avgdetections, $mystats->checkpoint->avgdefense, $mystats->checkpoint->avgkills, $mystats->checkpoint->avgdamage, $mystats->checkpoint->avgtier );
   } else {
      $mystats->checkpoint->statsdate     = 0;
      $mystats->checkpoint->battles       = 0;
      $mystats->checkpoint->victories     = 0;
      $mystats->checkpoint->detections    = 0;
      $mystats->checkpoint->defense       = 0;
      $mystats->checkpoint->kills         = 0;
      $mystats->checkpoint->damage        = 0;
      $mystats->checkpoint->avgtier       = 0;
      $mystats->checkpoint->winrate       = 0;
      $mystats->checkpoint->avgkills      = 0;
      $mystats->checkpoint->avgdamage     = 0;
      $mystats->checkpoint->avgdetections = 0;
      $mystats->checkpoint->avgdefense    = 0;
      $mystats->checkpoint->wn7           = 0;
   }

   if( $mystats->current->battles > $mystats->checkpoint->battles ) {
      $mystats->interval->battles       = $mystats->current->battles - $mystats->checkpoint->battles;
      $mystats->interval->victories     = $mystats->current->victories - $mystats->checkpoint->victories;
      $mystats->interval->detections    = $mystats->current->detections - $mystats->checkpoint->detections;
      $mystats->interval->defense       = $mystats->current->defense - $mystats->checkpoint->defense;
      $mystats->interval->kills         = $mystats->current->kills - $mystats->checkpoint->kills;
      $mystats->interval->damage        = $mystats->current->damage - $mystats->checkpoint->damage;
      foreach( $mystats->current->tanks as $tankstat ) {
         $tank = new stdClass;

         $tank->tankname = $tankstat->tankname;
         $tank->tier = $tankstat->tier;
         $tank->battles = $tankstat->battles - $mystats->checkpoint->tanks[$tankstat->tankname]->battles;
         $tank->victories = $tankstat->victories - $mystats->checkpoint->tanks[$tankstat->tankname]->victories;
         if( $tank->battles > 0 ) $tank->winrate = round(( $tank->victories / $tank->battles * 100 ), 2 );

         if( $tankstat->battles != $mystats->checkpoint->tanks[$tankstat->tankname]->battles ) {
            $mystats->interval->tanks[$tank->tankname] = clone $tank;
         }
      }
      $mystats->interval->weightedBattles = $mystats->current->weightedBattles - $mystats->checkpoint->weightedBattles;
      $mystats->interval->avgtier       = round(( $mystats->interval->weightedBattles / $mystats->interval->battles ), 2 );
      $mystats->interval->winrate       = round(( $mystats->interval->victories / $mystats->interval->battles * 100 ), 2 );
      $mystats->interval->avgkills      = $mystats->interval->kills / $mystats->interval->battles;
      $mystats->interval->avgdamage     = $mystats->interval->damage / $mystats->interval->battles;
      $mystats->interval->avgdetections = $mystats->interval->detections / $mystats->interval->battles;
      $mystats->interval->avgdefense    = $mystats->interval->defense / $mystats->interval->battles;
      $mystats->interval->wn7           = calculateWN7( $mystats->interval->battles, $mystats->interval->winrate, $mystats->interval->avgdetections, $mystats->interval->avgdefense, $mystats->interval->avgkills, $mystats->interval->avgdamage, $mystats->interval->avgtier );

      $mystats->delta->winrate = $mystats->current->winrate - $mystats->checkpoint->winrate;
      $mystats->delta->avgtier = $mystats->current->avgtier - $mystats->checkpoint->avgtier;
      $mystats->delta->wn7 = $mystats->current->wn7 - $mystats->checkpoint->wn7;
      if( $mystats->delta->winrate > 0 ) $color["winrate"] = "green"; else $color["winrate"] = "red";
      if( $mystats->delta->winrate > 0 ) $token["winrate"] = "+";
      if( $mystats->delta->avgtier > 0 ) $color["avgtier"] = "green"; else $color["avgtier"] = "red";
      if( $mystats->delta->avgtier > 0 ) $token["avgtier"] = "+";
      if( $mystats->delta->wn7 > 0 ) $color["wn7"] = "green"; else $color["wn7"] = "red";
      if( $mystats->delta->wn7 > 0 ) $token["wn7"] = "+";

   }

   $mystats->current->winrate          = round( $mystats->current->winrate, 2 );
   $mystats->checkpoint->winrate       = round( $mystats->checkpoint->winrate, 2 );
   $mystats->delta->winrate            = round( $mystats->delta->winrate, 2 );
   $mystats->current->avgtier          = round( $mystats->current->avgtier, 2 );
   $mystats->checkpoint->avgtier       = round( $mystats->checkpoint->avgtier, 2 );
   $mystats->delta->avgtier            = round( $mystats->delta->avgtier, 2 );
   $mystats->current->avgkills         = round( $mystats->current->avgkills, 2 );
   $mystats->checkpoint->avgkills      = round( $mystats->checkpoint->avgkills, 2 );
   $mystats->delta->avgkills           = round( $mystats->delta->avgkills, 2 );
   $mystats->current->avgdamage        = round( $mystats->current->avgdamage, 2 );
   $mystats->checkpoint->avgdamage     = round( $mystats->checkpoint->avgdamage, 2 );
   $mystats->delta->avgdamage          = round( $mystats->delta->avgdamage, 2 );
   $mystats->current->avgdetections    = round( $mystats->current->avgdetections, 2 );
   $mystats->checkpoint->avgdetections = round( $mystats->checkpoint->avgdetections, 2 );
   $mystats->delta->avgdetections      = round( $mystats->delta->avgdetections, 2 );
   $mystats->current->avgdefense       = round( $mystats->current->avgdefense, 2 );
   $mystats->checkpoint->avgdefense    = round( $mystats->checkpoint->avgdefense, 2 );
   $mystats->delta->avgdefense         = round( $mystats->delta->avgdefense, 2 );

   if( $hitApi ) {
      $apiMsg = "updated from webservice";
   } else {
      $nextHit = ceil(( 3600 - ( $currentTime - $lastApiHit )) / 60 );
      $apiMsg = "cached; next check in {$nextHit} minutes";
   }

   foreach( $mystats->interval->tanks as $tankname => $vehicle ) {
      $tankSort[$tankname] = $vehicle->battles;
   }
   asort( $tankSort );
   $tankSort = array_reverse( $tankSort );

   $properDatestamp = date( "Y-m-d H:i", $mystats->current->statsdate );
   echo <<<EOE
<span>Stats as of {$properDatestamp} EDT <font size=-2>({$apiMsg})</font></span>

<table border=0 cellpadding=3 cellspacing=0>
   <thead>
   <tr bgcolor=e2e2e2>
      <td></td>
      <td><b>Checkpoint</b></td>
      <td><b>Current</b></td>
      <td><b>Interval</b></td>
   </tr>
   </thead>
   <tr>
      <td><b>Battles Played / <font size=-1><b>Won</b></font></b></td>
      <td>{$mystats->checkpoint->battles} / <font size=-1>{$mystats->checkpoint->victories}</font></td>
      <td>{$mystats->current->battles} / <font size=-1>{$mystats->current->victories}</font></td>
      <td>{$mystats->interval->battles} / <font size=-1>{$mystats->interval->victories}</font></td>
   </tr>
   <tr>
      <td><b>Win Rate</b></td>
      <td>{$mystats->checkpoint->winrate}%</td>
      <td>{$mystats->current->winrate}%</td>
      <td>{$mystats->interval->winrate}% <font size=-1 color={$color["winrate"]}>({$token["winrate"]}{$mystats->delta->winrate}%)</font></td>
   </tr>
   <tr>
      <td><b>Enemies Spotted</b></td>
      <td>{$mystats->checkpoint->detections}</td>
      <td>{$mystats->current->detections}</td>
      <td>{$mystats->interval->detections}</td>
   </tr>
   <tr>
      <td><b>Enemies Killed / <font size=-1><b>Avg.</b></font></b></td>
      <td>{$mystats->checkpoint->kills} / <font size=-1>{$mystats->checkpoint->avgkills}</font></td>
      <td>{$mystats->current->kills} / <font size=-1>{$mystats->current->avgkills}</font></td>
      <td>{$mystats->interval->kills} / <font size=-1>{$mystats->interval->avgkills}</font></td>
   </tr>
   <tr>
      <td><b>Average Defense</b></td>
      <td>{$mystats->checkpoint->avgdefense}</td>
      <td>{$mystats->current->avgdefense}</td>
      <td>{$mystats->interval->avgdefense}</td>
   </tr>
   <tr>
      <td><b>Average Damage</b></td>
      <td>{$mystats->checkpoint->avgdamage}</td>
      <td>{$mystats->current->avgdamage}</td>
      <td>{$mystats->interval->avgdamage}</td>
   </tr>
   <tr>
      <td><b>Average Tier</b></td>
      <td>{$mystats->checkpoint->avgtier}</td>
      <td>{$mystats->current->avgtier}</td>
      <td>{$mystats->interval->avgtier} <font size=-1 color={$color["avgtier"]}>({$token["avgtier"]}{$mystats->delta->avgtier})</font></td>
   </tr>
   <tr>
      <td><b>WN7 Rating</b></td>
      <td>{$mystats->checkpoint->wn7}</td>
      <td>{$mystats->current->wn7}</td>
      <td>{$mystats->interval->wn7} <font size=-1 color={$color["wn7"]}>({$token["wn7"]}{$mystats->delta->wn7})</font></td>
   </tr>
</table>

<br /><br />
<div>

<table border=0 cellpadding=3 cellspacing=0>
   <thead>
   <tr bgcolor=e2e2e2>
      <td><b>Tanks Played<b/></td>
      <td><b>Battles</b></td>
      <td><b>Victories</b></td>
      <td><b>Winrate</b></td>
   </tr>
   </thead>
EOE;

foreach( $tankSort as $tankname => $battles ) {
   echo <<<EOE
   <tr>
      <td><b>{$mystats->interval->tanks[$tankname]->tankname}</b></td>
      <td>{$mystats->interval->tanks[$tankname]->battles}</td>
      <td>{$mystats->interval->tanks[$tankname]->victories}</td>
      <td>{$mystats->interval->tanks[$tankname]->winrate}%</td>
   </tr>

EOE;
}
echo "</table>\r\n</div>\r\n";

function calculateWN7( $battles, $winrate, $detections, $defense, $kills, $damage, $averagetier ) {
//   echo "WN7 = \tbattles: ${battles}, winrate: {$winrate}, detections: {$detections}, defense: {$defense}, kills: {$kills}, avgtier: {$averagetier}<br />\r\n";
   $wn7 = round(( 1240 - 1040 / ( pow( min( $averagetier, 6 ), 0.164 ))) * $kills
      + $damage * 530 / ( 184 * exp( 0.24 * $averagetier ) + 130 )
      + $detections * 125 * ( min( $averagetier, 3 )) / 3
      + min( $defense, 2.2 ) * 100
      + (( 185 / ( 0.17 + exp(( $winrate - 35 ) * ( -0.134 )))) - 500 ) * 0.45
      - (( 5 - min( $averagetier, 5 )) * 125 )
      / ( 1 + exp(( $averagetier - pow(( $battles / 220 ), ( 3 / $averagetier ))) * 1.5 )));

   return $wn7;
}

function cacheApiData( $data, $playerId, $currentTime ) {
   if( !file_exists( "cache" )) mkdir( "cache" );
   if( !file_exists( "cache/{$playerId}" )) mkdir( "cache/{$playerId}" );

   $cacheFile = fopen( "cache/{$playerId}/{$currentTime}.cache", "w+" );
   fwrite( $cacheFile, $data );
}
?>
