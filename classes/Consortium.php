<?php

namespace Vanderbilt\CareerDevLibrary;

class Consortium {
	public static function findNextMeeting($startTime = NULL) {
		$ts = self::findNextMeetingTs($startTime);
		return self::formatLongDate($ts);
	}

	public static function findNextMeetingTs($startTime = NULL) {
		if (!$startTime) {
			if (date("Y-m") == "2020-02") {
				# start twice a month in 2020-03
				$startTime = strtotime("2020-03-01");
			} else {
				$startTime = time();
			}
		}
		$midnightTs = strtotime(date("Y-m-d 23:59:59", $startTime));
	
		$firstWedTs = self::findXthWednesdayTs(1, $startTime);
		if ($firstWedTs > $midnightTs) {
			return $firstWedTs;
		}

		$thirdWedTs = self::findXthWednesdayTs(3, $startTime);
		if ($thirdWedTs > $midnightTs) {
			return $thirdWedTs;
		}

		$month = date("m");
		$year = date("Y");
		$month++;
		if ($month > 12) {
			$month = "1";
			$year++;
		}
		$startOfNextMonthTs = strtotime($year."-".$month."-01");
		return self::findXthWednesdayTs(1, $startOfNextMonthTs);
	}

	public static function findXthWednesdayTs($numWednesdaySought, $timeInMonth) {
		$dayOfWeekWed = 3;
		$oneDay = 24 * 3600;
		$month = date("m", $timeInMonth);
		$year = date("Y", $timeInMonth);
		$day = ($numWednesdaySought - 1) * 7 + 1;
		$ts = strtotime("$year-$month-$day");
		while (date("N", $ts) != $dayOfWeekWed) {
			$ts += $oneDay;
		}
		return $ts;
	}

	public static function formatLongDate($ts) {
		return date("l, F j", $ts);
	}

	public static function meetingIsToday() {
		return (self::formatLongDate(time()) == self::findNextMeeting());
	}
}
