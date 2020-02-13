<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");

# This class runs unit-testing across a class. It runs all methods within the class that are suffixed with _test.
# The UnitTester class is passed as the first argument to these _test methods. The UnitTester instance will run
# the assertions. To differentiate between the assert calls in the test interface, a tag can be provided via the
# tag($tag) method. 

class UnitTester
{
	public function analyze($obj) {
		$this->run($obj);
	}

	/********************************** ASSERT METHODS *****************************/

	public function assertMatch($re, $str) {
		$bool = preg_match($re, $str);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertMatch TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertMatch FALSE: $re does not match to ".htmlspecialchars($str); 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertNotMatch($re, $str) {
		$bool = !preg_match($re, $str);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotMatch TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotMatch FALSE: $re matches ".htmlspecialchars($str); 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertIn($a, $ary) {
		$bool = in_array($a, $ary);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertIn TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertIn FALSE: $a is not in ary"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertEmpty($ary) {
		$bool = empty($ary);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertEmpty TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertEmpty FALSE: ".json_encode($ary); 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertNotEmpty($ary) {
		$bool = !empty($ary);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotEmpty TRUE ".count($ary)." items"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotEmpty FALSE"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertNotIn($a, $ary) {
		$bool = !in_array($a, $ary);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotIn TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotIn FALSE: $a is in ary"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function tag($tag) {
		if ($tag) {
			$this->tag = $tag.": ";
		}
	}
	public function assertNull($obj) {
		$bool = ($obj === NULL);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNull TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNull FALSE: $obj is not NULL"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertNotNull($obj) {
		$bool = ($obj !== NULL);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotNull TRUE $obj"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotNull FALSE: $obj is NULL"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertEqual($a, $b) {
		$bool = ($a == $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertEqual TRUE $a"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertEqual FALSE: $a != $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}
	
	public function assertTripleEqual($a, $b) {
		$bool = ($a === $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertTripleEqual TRUE $a"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertTripleEqual FALSE: $a !== $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}
	
	public function assertNotZero($a) {
		$bool = ($a != 0);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotZero TRUE $a"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotZero FALSE"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}
	
	public function assertNotEqual($a, $b) {
		$bool = ($a != $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotEqual FALSE: $a == $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}
	
	public function assertNotTripleEqual($a, $b) {
		$bool = ($a !== $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotTripleEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotTripleEqual FALSE: $a === $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}
	
	public function assertBlank($str) {
		$bool = ($str === "");
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertBlank TRUE";
		} else {
			$this->currResults[$this->count] = $this->tag."assertBlank FALSE: '".htmlspecialchars($str)."' is not blank"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertLessThan($a, $b) {
		$bool = ($a < $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertLessThan TRUE $a < $b";
		} else {
			$this->currResults[$this->count] = $this->tag."assertLessThan FALSE: $a is not < $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertGreaterThan($a, $b) {
		$bool = ($a > $b);
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertGreaterThan TRUE $a > $b";
		} else {
			$this->currResults[$this->count] = $this->tag."assertGreaterThan FALSE: $a is not > $b"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	public function assertNotBlank($str) {
		$bool = ($str !== "");
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertNotBlank TRUE";
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotBlank FALSE: '".htmlspecialchars($str)."' is blank"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	# the test pages key in on FALSE, so don't rename to assertFalse
	public function assertNotTrue($bool, $label = "") {
		if (!$bool) {
			$this->currResults[$this->count] = $this->tag."assertNotTrue $label TRUE";
		} else {
			$this->currResults[$this->count] = $this->tag."assertNotTrue $label FALSE"; 
		}
		$this->count++;
		$this->tag = "";
		return !$bool;
	}

	public function assertTrue($bool, $label = "") {
		if ($bool) {
			$this->currResults[$this->count] = $this->tag."assertTrue $label TRUE";
		} else {
			$this->currResults[$this->count] = $this->tag."assertTrue $label FALSE"; 
		}
		$this->count++;
		$this->tag = "";
		return $bool;
	}

	/**************************************************************************/

	public function getResults() {
		return $this->testResults;
	}

	public function getFailures() {
		$badResults = array();
		foreach ($this->testResults as $method => $ary) {
			foreach ($ary['results'] as $result) {
				if (preg_match("/FALSE/", $result)) {
					if (!isset($badResults[$method])) {
						$badResults[$method] = array();
					}
					array_push($badResults[$method], $result);
				}
			}
		}
		return $badResults;
	}

	public function reset() {
		$this->testResults = array();
	}

	public function setPid($myPid) {
		$this->pid = $myPid;
	}

	private $testResults = array(); // an array indexed by method name
					// contains: array("results" => all of the test results,
					//		   "description" => plain-text description,
					//		)
	private $currMethod = "";       // current test that's being run
	private $currResults = array(); // current results that are being saved
	private $count = 1;		// keeps count of the assert statements
					//  starts at 1 for each method
	private $pid = "";
	private $tag = "";

	private function run($objToTest) {
		$classes = array(
					"/_test$/" => $objToTest,
				);

		$this->currMethod = "";
		$this->currResults = array();
		foreach ($classes as $re => $obj) {
			$methods = get_class_methods(get_class($obj));
			foreach ($methods as $method) {
				if (preg_match($re, $method)) {
					$this->currMethod = $method;
					$this->count = 1;

					$origPost = $_POST;
					$origGet = $_GET;
					$obj->$method($this);
					$_POST = $origPost;
					$_GET = $origGet;
				}
				if ($this->currMethod != "") {
					$ary = array(
							"results" => $this->currResults,
							);
					foreach ($methods as $method2) {
						if ($method2 == $method."_descript") {
							$descript = call_user_func(array($obj, $method2));
							$ary["description"] = $descript;
							break;
						}
					}
					$this->testResults[$this->currMethod] = $ary;
				}
				$this->currMethod = "";
				$this->currResults = array();
			}
		}
	}
}

