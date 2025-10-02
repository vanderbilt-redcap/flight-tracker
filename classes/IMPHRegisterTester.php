<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class IMPHRegisterTester
{
	public function __construct($token, $server, $pid) {
		$this->metadata = Download::metadata($token, $server);
		$this->module = Application::getModule();
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->testRecordId = "99999";
	}

	public function LDAP_test($tester) {
		$uid = "pearsosj";
		$first = "Scott";
		$last = "Pearson";

		$json = '{"0":{"modifytimestamp":{"count":1,"0":"20191024165009Z"},"0":"modifytimestamp","modifiersname":{"count":1,"0":"uid=identity manager,ou=directory services,dc=vanderbilt,dc=edu"},"1":"modifiersname","vanderbiltpersonlastepwchgdate":{"count":1,"0":"20191024"},"2":"vanderbiltpersonlastepwchgdate","mail":{"count":1,"0":"scott.j.pearson@vumc.org"},"3":"mail","o":{"count":1,"0":"VMC"},"4":"o","telephonenumber":{"count":1,"0":"615-322-4806"},"5":"telephonenumber","edupersonprimaryaffiliation":{"count":1,"0":"staff"},"6":"edupersonprimaryaffiliation","edupersonaffiliation":{"count":4,"0":"staff","1":"employee","2":"member","3":"affiliate"},"7":"edupersonaffiliation","displayname":{"count":1,"0":"Pearson, Scott James"},"8":"displayname","vanderbiltpersonjobstatus":{"count":1,"0":"N"},"9":"vanderbiltpersonjobstatus","vanderbiltpersonepinumber":{"count":1,"0":"0003237120"},"10":"vanderbiltpersonepinumber","givenname":{"count":1,"0":"Scott"},"11":"givenname","objectclass":{"count":7,"0":"top","1":"person","2":"inetOrgPerson","3":"eduPerson","4":"vanderbiltPerson","5":"posixAccount","6":"organizationalPerson"},"12":"objectclass","vanderbiltpersonemployeeclass":{"count":1,"0":"Staff"},"13":"vanderbiltpersonemployeeclass","departmentnumber":{"count":1,"0":"VICTR - 104242"},"14":"departmentnumber","vanderbiltpersonactiveemployee":{"count":1,"0":"Y"},"15":"vanderbiltpersonactiveemployee","uid":{"count":1,"0":"'.$uid.'"},"16":"uid","uidnumber":{"count":1,"0":"170738"},"17":"uidnumber","cn":{"count":1,"0":"Scott James Pearson"},"18":"cn","vanderbiltpersonhrdeptname":{"count":1,"0":"VICTR"},"19":"vanderbiltpersonhrdeptname","vanderbiltpersonsecurity":{"count":1,"0":"PUBLIC"},"20":"vanderbiltpersonsecurity","vanderbiltpersonhrdepttype":{"count":1,"0":"Medical"},"21":"vanderbiltpersonhrdepttype","gidnumber":{"count":1,"0":"10000"},"22":"gidnumber","vanderbiltpersoncommonid":{"count":1,"0":"234795"},"23":"vanderbiltpersoncommonid","vanderbiltpersonhrdeptnumber":{"count":1,"0":"104242"},"24":"vanderbiltpersonhrdeptnumber","homedirectory":{"count":1,"0":"\/home\/pearsosj"},"25":"homedirectory","vanderbiltpersonemployeeid":{"count":1,"0":"0118480"},"26":"vanderbiltpersonemployeeid","sn":{"count":1,"0":"Pearson"},"27":"sn","creatorsname":{"count":1,"0":"cn=prod idm resource manager,ou=application users,dc=vanderbilt,dc=edu"},"28":"creatorsname","createtimestamp":{"count":1,"0":"20140103181825Z"},"29":"createtimestamp","loginshell":{"count":1,"0":"\/bin\/sh"},"30":"loginshell","count":31,"dn":"uid=pearsosj,ou=people,dc=vanderbilt,dc=edu"},"count":1}';
		$info = json_decode($json, true);
		$ldapUid = LDAP::findField($info, "uid");
		if (is_array($ldapUid)) {
			$ldapUid = $ldapUid[0];
		}
		$tester->tag("findField: uid");
		$tester->assertEqual($ldapUid, $uid);

		$vunet = LDAP::getVUNet($first, $last);
		if (is_array($vunet)) {
			$vunet = $vunet[0];
		}
		$tester->tag("getVUNet =? $uid");
		$tester->assertEqual($vunet, $uid);

		$nameFields = ["givenname" => $first, "sn" => $last];
		$info2 = LDAP::getLDAPByMultiple(array_keys($nameFields), array_values($nameFields));
		$tester->tag("Count for $first $last >= 1");
		$tester->assertTrue($info2["count"] >= 1);
	}

	public function emailMgmtSmokeTest_test_DISABLED($tester) {
		$recordIds = Download::recordIds($this->token, $this->server);
		$mgr = new EmailManager($this->token, $this->server, $this->pid, $this->module, $this->metadata);
		$mgr->loadRealData();

		foreach ($mgr->getSettingsNames() as $name) {
			$emailSetting = $mgr->getItem($name);
			$tester->tag("$name who: ".json_encode($emailSetting["who"]));
			$tester->assertNotEmpty($emailSetting["who"]);
			$tester->tag("$name when: ".json_encode($emailSetting["when"]));
			$tester->assertNotEmpty($emailSetting["when"]);
			$tester->tag("$name what");
			$tester->assertNotEmpty($emailSetting["what"]);

			$who = $emailSetting["who"];
			$when = $emailSetting["when"];
			$rows = $mgr->getRows($who);

			$toSendTs = strtotime($when['initial_time']);
			if ($toSendTs < time()) {
				# in past
				$currTime = time();
				$daysToCheck = 21;
				for ($newTs = $currTime; $newTs < $currTime + $daysToCheck * 24 * 3600; $newTs += 60) {
					$tester->tag("$name: ".date("Y-m-d H:i", $newTs)." vs. ".date("Y-m-d H:i", $toSendTs));
					$tester->assertTrue(!$mgr->isReadyToSend($newTs, $toSendTs));
				}
			} else {
				# in future
				$oneMinuteAfter = $toSendTs + 60;
				$oneMinuteBefore = $toSendTs - 60;
				$tester->tag("$name after: ".date("Y-m-d H:i", $oneMinuteAfter)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue(!$mgr->isReadyToSend($oneMinuteAfter, $toSendTs));
				$tester->tag("$name before: ".date("Y-m-d H:i", $oneMinuteBefore)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue(!$mgr->isReadyToSend($oneMinuteBefore, $toSendTs));

				$oneOff = $toSendTs + 1;
				if (date("Y-m-d H:i", $oneOff) != date("Y-m-d H:i", $toSendTs)) {
					$oneOff = $toSendTs - 1;
				}
				$tester->tag("$name one off: ".date("Y-m-d H:i", $oneOff)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue($mgr->isReadyToSend($oneOff, $toSendTs));
			}

			if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
				$tester->tag("Setting $name ".json_encode($who)." record count - might be unequal if one name not in database");
				$tester->assertEqual(count($recordIds), count($rows));
				$tester->tag("Setting $name rows not zero - might be zero if all names not in database");
				$tester->assertNotEqual(count($rows), 0);
			} elseif ($who["individuals"]) {
				$checkedIndivs = $who["individuals"];
				$tester->tag("Setting $name ".json_encode($who)." count");
				$tester->assertEqual(count($checkedIndivs), count($rows));
				$tester->tag("Setting $name rows not zero");
				$tester->assertNotEqual(count($rows), 0);
			} elseif ($who['filter'] == "some") {
				# $who['filter'] == "some"
				$names = $mgr->getNames($who);
				$emails = $mgr->getNames($who);

				$tester->tag("Setting $name count(names) == count(rows)");
				$tester->assertEqual(count($names), count($rows));
				$tester->tag("Setting $name count(emails) == count(rows)");
				$tester->assertEqual(count($emails), count($rows));
				foreach ($names as $recordId => $indivName) {
					$tester->tag("Record $recordId is in recordIds");
					$tester->assertIn($recordId, $recordIds);
				}
			} else {
				$tester->assertBlank("Illegal Who ".json_encode($who));
			}
		}

	}

	private $pid;
	private $token;
	private $server;
	private $module;
	private $metadata;
	private $testRecordId;
}
