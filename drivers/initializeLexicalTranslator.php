<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getVanderbiltLexicalTranslatorJSON() {
	return '{"VCTRS":"Internal K","VCRS":"K12\/KL2","VPSD":"Internal K","VFRS":"Internal K","VA Merit":"R01 Equivalent","VA Career":"K Equivalent","VACDA":"K Equivalent","VA CDA":"K Equivalent","VCORCDP":"K12\/KL2","VEHSS":"K12\/KL2","NIEHS":"K12\/KL2","VEMRT":"K12\/KL2","VICMIC":"K12\/KL2","V-POCKET":"K12\/KL2","BIRCWH":"K12\/KL2","Human Frontiers in Science":"K Equivalent","Clinical Scientist":"K Equivalent","FTF":"K Equivalent","Robert Wood Johnson":"K Equivalent","ACS":"K Equivalent","Dermatology Foundation":"K Equivalent","Damon Runyon Cancer Research Foundation":"K Equivalent","AHA":"K Equivalent","Burroughs Wellcome":"K Equivalent","NASPGHAN":"K Equivalent","CDHNF":"K Equivalent","PhARMA":"K Equivalent","NKF":"K Equivalent","SDG":"K Equivalent","CDA":"K Equivalent","KAward":"Individual K","K Award":"Individual K","DOD":"R01 Equivalent","Department of Defense":"R01 Equivalent","NCI-K12":"K12\/KL2","NCI K12":"K12\/KL2","NCIK12":"K12\/KL2","PedsK12":"K12\/KL2","Peds K12":"K12\/KL2","LUNGevity":"K Equivalent"}';
}

function initialize($token, $server, $pid, $records) {
	if (Application::isVanderbilt()) {
		$json = getVanderbiltLexicalTranslatorJSON();
		$lexTranslator = new GrantLexicalTranslator($token, $server, Application::getModule());
		$lexTranslator->loadData(json_decode($json, true));
	}
}
