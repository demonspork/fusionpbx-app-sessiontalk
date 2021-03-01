<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX
	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2016
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

if ($domains_processed == 1) {
	$x=0;
	$array['email_templates'][$x]['email_template_uuid'] = '9a586bc8-9db6-4785-9bb6-eaaea6d69a3f';
	$array['email_templates'][$x]['template_language'] = 'en-us';
	$array['email_templates'][$x]['template_category'] = 'sessiontalk';
	$array['email_templates'][$x]['template_subcategory'] = 'windows-app';
	$array['email_templates'][$x]['template_subject'] = 'Subject, Windows App Welcome Email';
	$array['email_templates'][$x]['template_body'] .= "<html>\n";
	$array['email_templates'][$x]['template_body'] .= "<body>\n";
	$array['email_templates'][$x]['template_body'] .= "Click this link to install and activate the Windows Softphone: \${windows-softphone-link}.\n";
	$array['email_templates'][$x]['template_body'] .= "If you already have an app installed, please uninstall it first.\n";
	$array['email_templates'][$x]['template_body'] .= "</body>\n";
	$array['email_templates'][$x]['template_body'] .= "</html>\n";
	$array['email_templates'][$x]['template_type'] = 'html';
	$array['email_templates'][$x]['template_enabled'] = 'true';
	$array['email_templates'][$x]['template_description'] = '';
	$x++;

    $array['device_vendors'][0]['device_vendor_uuid'] = 'e0d09235-4c1d-423f-8f38-aa477e18362b';
    $array['device_vendors'][0]['name'] = "sessiontalk";
    $array['device_vendors'][0]['enabled'] = 'true';

    $p = new permissions;

    $database = new database;
    $database->app_name = 'sessiontalk';
    $database->app_uuid = '85774108-716c-46cb-a34b-ce80b212bc82';
    $database->save($array);
    unset($array);

    $p->delete('device_vendor_add', 'temp');


	//build array of email template uuids
	foreach ($array['email_templates'] as $row) {
		if (is_uuid($row['email_template_uuid'])) {
			$uuids[] = $row['email_template_uuid'];
		}
	}

	//add the email templates to the database
	if (is_array($uuids) && @sizeof($uuids) != 0) {
		$sql = "select * from v_email_templates where ";
		foreach ($uuids as $index => $uuid) {
			$sql_where[] = "email_template_uuid = :email_template_uuid_".$index;
			$parameters['email_template_uuid_'.$index] = $uuid;
		}
		$sql .= implode(' or ', $sql_where);
		$database = new database;
		$email_templates = $database->select($sql, $parameters, 'all');
		unset($sql, $sql_where, $parameters);

		//remove templates that already exist from the array
		foreach ($array['email_templates'] as $index => $row) {
			if (is_array($email_templates) && @sizeof($email_templates) != 0) {
				foreach($email_templates as $email_template) {
					if ($row['email_template_uuid'] == $email_template['email_template_uuid']) {
						unset($array['email_templates'][$index]);
					}
				}
			}
		}
		unset($email_templates, $index);
	}

	//add the missing email templates
	if (is_array($array['email_templates']) && @sizeof($array['email_templates']) != 0) {
		//add the temporary permission
		$p = new permissions;
		$p->add("email_template_add", 'temp');
		$p->add("email_template_edit", 'temp');

		//save the data
		$database = new database;
		$database->app_name = 'email_templates';
		$database->app_uuid = '8173e738-2523-46d5-8943-13883befd2fd';
		$database->save($array);
		//$message = $database->message;

		//remove the temporary permission
		$p->delete("email_template_add", 'temp');
		$p->delete("email_template_edit", 'temp');
	}

	//remove the array
	unset($array);

}

?>
