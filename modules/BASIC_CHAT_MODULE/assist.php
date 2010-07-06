<?php
   /*
   ** Author: Derroylo (RK2)
   ** Description: Creates a Assist Macro
   ** Version: 1.0
   **
   ** Developed for: Budabot(http://sourceforge.net/projects/budabot)
   **
   ** Date(created): 17.12.2006
   ** Date(last modified): 25.02.2006
   ** 
   ** Copyright (C) 2006 Carsten Lohmann
   **
   ** Licence Infos: 
   ** This file is part of Budabot.
   **
   ** Budabot is free software; you can redistribute it and/or modify
   ** it under the terms of the GNU General Public License as published by
   ** the Free Software Foundation; either version 2 of the License, or
   ** (at your option) any later version.
   **
   ** Budabot is distributed in the hope that it will be useful,
   ** but WITHOUT ANY WARRANTY; without even the implied warranty of
   ** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   ** GNU General Public License for more details.
   **
   ** You should have received a copy of the GNU General Public License
   ** along with Budabot; if not, write to the Free Software
   ** Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
   */

global $assist;
if (preg_match("/^assist$/i", $message)) {
  	if(!isset($assist)) {
		$msg = "No assist set atm.";
		$this->send($msg, $sendto);
	}
} else if (preg_match("/^assist (.+)$/i", $message, $arr)) {
    $nameArray = explode(' ', $arr[1]);
	
	if (count($nameArray) == 1) {
		$name = ucfirst(strtolower($arr[1]));
		$uid = $this->get_uid($name);
		if ($type == "priv" && !isset($this->chatlist[$name])) {
			$msg = "Player <highlight>$name<end> isn't in this bot.";
			$this->send($msg, $sendto);
		}
		
		if(!$uid) {
			$msg = "Player <highlight>$name<end> does not exist.";
			$this->send($msg, $sendto);
		}
		
		$link = "<header>::::: Assist Macro for $name :::::\n\n";
		$link .= "<a href='chatcmd:///macro $name /assist $name'>Click here to make an assist $name macro</a>";
		$assist = $this->makeLink("Assist $name Macro", $link);
	} else {
		forEach ($nameArray as $key => $name) {
			$name = ucfirst(strtolower($name));
			if ($type == "priv" && !isset($this->chatlist[$name])) {
				$msg = "Player <highlight>$name<end> isn't in this bot.";
				$this->send($msg, $sendto);
			}
			
			if (!$uid) {
				$msg = "Player <highlight>$name<end> does not exist.";
				$this->send($msg, $sendto);
			}
			$nameArray[$key] = "/assist $name";
		}
		
		// reverse array so that the first player will be the primary assist, and so on
		$nameArray = array_reverse($nameArray);
		$assist = '/macro assist ' . implode(" \\n ", $nameArray);
	}
} else {
	$syntax_error = true;
}

if ($assist != '') {
	$this->send($assist, $sendto);
	
	// send message 2 more times (3 total) if used in private channel
	if ($type == "priv") {
		$this->send($assist, $sendto);
		$this->send($assist, $sendto);
	}
}
?>