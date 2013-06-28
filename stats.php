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
      $mystats->statsdate->current  = $statsObject->data->updated_at;
      $mystats->battles->current    = $statsObject->data->summary->battles_count;
      $mystats->victories->current  = $statsObject->data->summary->wins;
      $mystats->detections->current = $statsObject->data->battles->spotted;
      $mystats->defense->current    = $statsObject->data->battles->dropped_capture_points;
      $mystats->kills->current      = $statsObject->data->battles->frags;
      $mystats->damage->current     = $statsObject->data->battles->damage_dealt;
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
      $mystats->weightedBattles->current = $weightedBattles;
      $mystats->avgtier->current    = $mystats->weightedBattles->current / $mystats->battles->current;

      $checkpoint = 0;
      if(( $currentTime - $statsObject->data->updated_at ) >= 21600 ) $checkpoint = 1;
      $sql = "insert into wotstats ( playerid, cachedate, statsdate, battles, victories, detections, defense, kills, damage, checkpoint ) values ( {$playerId}, '{$currentTime}', '{$mystats->statsdate->current}', {$mystats->battles->current}, {$mystats->victories->current}, {$mystats->detections->current}, {$mystats->defense->current}, {$mystats->kills->current}, {$mystats->damage->current}, {$checkpoint} )";
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
      $mystats->statsdate->current  = (int) $ls["statsdate"];
      $mystats->battles->current    = (int) $ls["battles"];
      $mystats->victories->current  = (int) $ls["victories"];
      $mystats->detections->current = (int) $ls["detections"];
      $mystats->defense->current    = (int) $ls["defense"];
      $mystats->kills->current      = (int) $ls["kills"];
      $mystats->damage->current     = (int) $ls["damage"];
      $mystats->avgtier->current    = (float) $ls["avgtier"];

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
      $mystats->weightedBattles->current = $weightedBattles;
      $mystats->avgtier->current    = $mystats->weightedBattles->current / $mystats->battles->current;

   }
   $mystats->winrate->current       = $mystats->victories->current / $mystats->battles->current * 100;
   $mystats->avgkills->current      = $mystats->kills->current / $mystats->battles->current;
   $mystats->avgdamage->current     = $mystats->damage->current / $mystats->battles->current;
   $mystats->avgdetections->current = $mystats->detections->current / $mystats->battles->current;
   $mystats->avgdefense->current    = $mystats->defense->current / $mystats->battles->current;
   $mystats->wn7->current           = calculateWN7( $mystats->battles->current, $mystats->winrate->current, $mystats->avgdetections->current, $mystats->avgdefense->current, $mystats->avgkills->current, $mystats->avgdamage->current, $mystats->avgtier->current );

   // load last checkpoint
   $hasCheckpoint = false;
   $result = $mysqli->query( "select count(id) as checkpoints from wotstats where playerid = {$playerId} and checkpoint = 1" );
   $checkpoints = $result->fetch_assoc();
   if( (int) $checkpoints["checkpoints"] > 0 ) $hasCheckpoint = true;

   if( $hasCheckpoint ) {
      $result = $mysqli->query( "select * from wotstats where playerid = {$playerId} and checkpoint = 1 order by cachedate desc limit 1" );
      $cp = $result->fetch_assoc();
      $mystats->statsdate->checkpoint     = $cp["statsdate"];
      $mystats->battles->checkpoint       = $cp["battles"];
      $mystats->victories->checkpoint     = $cp["victories"];
      $mystats->detections->checkpoint    = $cp["detections"];
      $mystats->defense->checkpoint       = $cp["defense"];
      $mystats->kills->checkpoint         = $cp["kills"];
      $mystats->damage->checkpoint        = $cp["damage"];
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
      $mystats->weightedBattles->checkpoint = $weightedBattles;
      $mystats->avgtier->checkpoint       = $mystats->weightedBattles->checkpoint / $mystats->battles->checkpoint;
      $mystats->winrate->checkpoint       = $mystats->victories->checkpoint / $mystats->battles->checkpoint * 100;
      $mystats->avgkills->checkpoint      = $mystats->kills->checkpoint / $mystats->battles->checkpoint;
      $mystats->avgdamage->checkpoint     = $mystats->damage->checkpoint / $mystats->battles->checkpoint;
      $mystats->avgdetections->checkpoint = $mystats->detections->checkpoint / $mystats->battles->checkpoint;
      $mystats->avgdefense->checkpoint    = $mystats->defense->checkpoint / $mystats->battles->checkpoint;
      $mystats->wn7->checkpoint           = calculateWN7( $mystats->battles->checkpoint, $mystats->winrate->checkpoint, $mystats->avgdetections->checkpoint, $mystats->avgdefense->checkpoint, $mystats->avgkills->checkpoint, $mystats->avgdamage->checkpoint, $mystats->avgtier->checkpoint );
   } else {
      $mystats->statsdate->checkpoint     = 0;
      $mystats->battles->checkpoint       = 0;
      $mystats->victories->checkpoint     = 0;
      $mystats->detections->checkpoint    = 0;
      $mystats->defense->checkpoint       = 0;
      $mystats->kills->checkpoint         = 0;
      $mystats->damage->checkpoint        = 0;
      $mystats->avgtier->checkpoint       = 0;
      $mystats->winrate->checkpoint       = 0;
      $mystats->avgkills->checkpoint      = 0;
      $mystats->avgdamage->checkpoint     = 0;
      $mystats->avgdetections->checkpoint = 0;
      $mystats->avgdefense->checkpoint    = 0;
      $mystats->wn7->checkpoint           = 0;
   }

   if( $mystats->battles->current > $mystats->battles->checkpoint ) {
      $mystats->battles->interval       = $mystats->battles->current - $mystats->battles->checkpoint;
      $mystats->victories->interval     = $mystats->victories->current - $mystats->victories->checkpoint;
      $mystats->detections->interval    = $mystats->detections->current - $mystats->detections->checkpoint;
      $mystats->defense->interval       = $mystats->defense->current - $mystats->defense->checkpoint;
      $mystats->kills->interval         = $mystats->kills->current - $mystats->kills->checkpoint;
      $mystats->damage->interval        = $mystats->damage->current - $mystats->damage->checkpoint;
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
      $mystats->weightedBattles->interval = $mystats->weightedBattles->current - $mystats->weightedBattles->checkpoint;
      $mystats->avgtier->interval       = round(( $mystats->weightedBattles->interval / $mystats->battles->interval ), 2 );
      $mystats->winrate->interval       = round(( $mystats->victories->interval / $mystats->battles->interval * 100 ), 2 );
      $mystats->avgkills->interval      = $mystats->kills->interval / $mystats->battles->interval;
      $mystats->avgdamage->interval     = $mystats->damage->interval / $mystats->battles->interval;
      $mystats->avgdetections->interval = $mystats->detections->interval / $mystats->battles->interval;
      $mystats->avgdefense->interval    = $mystats->defense->interval / $mystats->battles->interval;
      $mystats->wn7->interval           = calculateWN7( $mystats->battles->interval, $mystats->winrate->interval, $mystats->avgdetections->interval, $mystats->avgdefense->interval, $mystats->avgkills->interval, $mystats->avgdamage->interval, $mystats->avgtier->interval );

      $mystats->winrate->delta = $mystats->winrate->current - $mystats->winrate->checkpoint;
      $mystats->avgtier->delta = $mystats->avgtier->current - $mystats->avgtier->checkpoint;
      $mystats->wn7->delta = $mystats->wn7->current - $mystats->wn7->checkpoint;
      if( $mystats->winrate->delta > 0 ) $color["winrate"] = "green"; else $color["winrate"] = "red";
      if( $mystats->winrate->delta > 0 ) $token["winrate"] = "+";
      if( $mystats->avgtier->delta > 0 ) $color["avgtier"] = "green"; else $color["avgtier"] = "red";
      if( $mystats->avgtier->delta > 0 ) $token["avgtier"] = "+";
      if( $mystats->wn7->delta > 0 ) $color["wn7"] = "green"; else $color["wn7"] = "red";
      if( $mystats->wn7->delta > 0 ) $token["wn7"] = "+";

   }

   $mystats->winrate->current          = round( $mystats->winrate->current, 2 );
   $mystats->winrate->checkpoint       = round( $mystats->winrate->checkpoint, 2 );
   $mystats->winrate->delta            = round( $mystats->winrate->delta, 2 );
   $mystats->avgtier->current          = round( $mystats->avgtier->current, 2 );
   $mystats->avgtier->checkpoint       = round( $mystats->avgtier->checkpoint, 2 );
   $mystats->avgtier->delta            = round( $mystats->avgtier->delta, 2 );
   $mystats->avgkills->current         = round( $mystats->avgkills->current, 2 );
   $mystats->avgkills->checkpoint      = round( $mystats->avgkills->checkpoint, 2 );
   $mystats->avgkills->delta           = round( $mystats->avgkills->delta, 2 );
   $mystats->avgdamage->current        = round( $mystats->avgdamage->current, 2 );
   $mystats->avgdamage->checkpoint     = round( $mystats->avgdamage->checkpoint, 2 );
   $mystats->avgdamage->delta          = round( $mystats->avgdamage->delta, 2 );
   $mystats->avgdetections->current    = round( $mystats->avgdetections->current, 2 );
   $mystats->avgdetections->checkpoint = round( $mystats->avgdetections->checkpoint, 2 );
   $mystats->avgdetections->delta      = round( $mystats->avgdetections->delta, 2 );
   $mystats->avgdefense->current       = round( $mystats->avgdefense->current, 2 );
   $mystats->avgdefense->checkpoint    = round( $mystats->avgdefense->checkpoint, 2 );
   $mystats->avgdefense->delta         = round( $mystats->avgdefense->delta, 2 );

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

   $properDatestamp = date( "Y-m-d H:i", $mystats->statsdate->current );
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
      <td>{$mystats->battles->checkpoint} / <font size=-1>{$mystats->victories->checkpoint}</font></td>
      <td>{$mystats->battles->current} / <font size=-1>{$mystats->victories->current}</font></td>
      <td>{$mystats->battles->interval} / <font size=-1>{$mystats->victories->interval}</font></td>
   </tr>
   <tr>
      <td><b>Win Rate</b></td>
      <td>{$mystats->winrate->checkpoint}%</td>
      <td>{$mystats->winrate->current}%</td>
      <td>{$mystats->winrate->interval}% <font size=-1 color={$color["winrate"]}>({$token["winrate"]}{$mystats->winrate->delta}%)</font></td>
   </tr>
   <tr>
      <td><b>Enemies Spotted</b></td>
      <td>{$mystats->detections->checkpoint}</td>
      <td>{$mystats->detections->current}</td>
      <td>{$mystats->detections->interval}</td>
   </tr>
   <tr>
      <td><b>Enemies Killed / <font size=-1><b>Avg.</b></font></b></td>
      <td>{$mystats->kills->checkpoint} / <font size=-1>{$mystats->avgkills->checkpoint}</font></td>
      <td>{$mystats->kills->current} / <font size=-1>{$mystats->avgkills->current}</font></td>
      <td>{$mystats->kills->interval} / <font size=-1>{$mystats->avgkills->interval}</font></td>
   </tr>
   <tr>
      <td><b>Average Defense</b></td>
      <td>{$mystats->avgdefense->checkpoint}</td>
      <td>{$mystats->avgdefense->current}</td>
      <td>{$mystats->avgdefense->interval}</td>
   </tr>
   <tr>
      <td><b>Average Damage</b></td>
      <td>{$mystats->avgdamage->checkpoint}</td>
      <td>{$mystats->avgdamage->current}</td>
      <td>{$mystats->avgdamage->interval}</td>
   </tr>
   <tr>
      <td><b>Average Tier</b></td>
      <td>{$mystats->avgtier->checkpoint}</td>
      <td>{$mystats->avgtier->current}</td>
      <td>{$mystats->avgtier->interval} <font size=-1 color={$color["avgtier"]}>({$token["avgtier"]}{$mystats->avgtier->delta})</font></td>
   </tr>
   <tr>
      <td><b>WN7 Rating</b></td>
      <td>{$mystats->wn7->checkpoint}</td>
      <td>{$mystats->wn7->current}</td>
      <td>{$mystats->wn7->interval} <font size=-1 color={$color["wn7"]}>({$token["wn7"]}{$mystats->wn7->delta})</font></td>
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
