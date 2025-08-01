<?php

namespace Vanderbilt\CareerDevLibrary\ajax;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Portal;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

class ajaxRouter
{
	/**
	 * @param $module CareerDev
	 * @return array|void
	 */
	public static function route($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id, $module) {
		$module->setupApplication();
		if (!empty($action) && !empty($payload) && $action == 'ajaxRouter') {
			switch ($payload['ajaxAction']) {
				case 'collaboratorBasicSearch':
					return Portal::searchForCollaboratorsNewBasic($payload, $payload['pids']);
				case 'getFlightTrackerPids':
					return Application::getPids();
			}
		}
	}
}
