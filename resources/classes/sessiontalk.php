<?php
/*
 * FusionPBX
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Copyright (C) 2010 - 2019
 * All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 */
include "root.php";

// define the st_device class
	class sessiontalk {

		public $db;
		public $domain_uuid;
		public $device_uuid;
		public $device_vendor_uuid;
		public $device_profile_uuid;
		public $extension;
		public $settings;
		public $template_dir;

		/**
		 * declare private variables
		 */
		private $app_name;
		private $app_uuid;
		private $credentials;
		private $permission_prefix;
		private $table;
		private $uuid_prefix;
		private $toggle_field;
		private $toggle_values;
		private $domain_name;

		public function __construct() {

			// assign private variables
			$this->app_name = 'sessiontalk';
			$this->app_uuid = '85774108-716c-46cb-a34b-ce80b212bc82';
		}

		public function __destruct() {
			foreach ($this as $key => $value) {
				unset($this->$key);
			}
		}

		public function get_domain_uuid() {
			return $this->domain_uuid;
		}

		public function set_extension($extension_details, $domain_uuid, $domain_name) {
			$this->domain_name = $domain_name;
			$this->domain_uuid = $domain_uuid;
			if (is_array($extension_details)) {
				$this->extension = $extension_details;
				$this->domain_uuid = $domain_uuid;
			}
			else {

				$sql = "SELECT e.extension_uuid, e.extension, e.description, e.number_alias ";
				$sql .= "FROM v_extensions AS e ";
				$sql .= "WHERE e.domain_uuid = :domain_uuid ";
				$sql .= "AND e.enabled = 'true' ";
				if (preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $extension_details)) {
					$sql .= "AND e.extension_uuid = :extension_uuid ";
					$parameters['extension_uuid'] = $extension_details;
				}
				else {
					$sql .= "AND e.extension = :extension ";
					$parameters['extension'] = $extension_details;
				}
				$parameters['domain_uuid'] = $domain_uuid ?: $this->domain_uuid;
				$database = new database();
				$this->extension = $database->select($sql, $parameters, 'row');
				unset($sql, $parameters, $database);
			}
		}

		public function get_credentials() {
			// get the username
			$username = $this->extension['extension'];
			if (isset($this->extension['number_alias']) && strlen($this->extension['number_alias']) > 0) {
				$username = $this->extension['number_alias'];
			}

			// Get the variables
			$key_rotation = $this->settings['key_rotation']['numeric'] * 2;
			$this->credentials['username'] = $username . "@" . $this->domain_name;
			$this->credentials['expiration'] = date("U") + $this->settings['qr_expiration']['numeric'];
			$this->credentials['providerid'] = $this->settings['provider_id']['text'];
			if (isset($this->credentials['providerid']) && strlen($this->credentials['providerid'] > 0)) {
				$this->credentials['providerid'] = ":" . $this->credentials['providerid'];
			}

			// Fetch the active keys for this domain
			$sql = "SELECT * FROM v_sessiontalk_keys ";
			$sql .= "WHERE domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
			$database = new database();
			$key = $database->select($sql, $parameters, 'row');
			unset($sql, $parameters);

			// check if there is a key
			if (!$key) {
				$key['sessiontalk_key_uuid'] = uuid();
				$key['domain_uuid'] = $this->domain_uuid;
				$key['key1'] = generate_password(32, 3);
				$key['expiration_date'] = date("U") + $key_rotation;
				$key_updated = true;
			} // check if it is time to rotate the key
			elseif ($key['expiration_date'] < $this->credentials['expiration']) {
				$key['key2'] = $key['key1'];
				$key['key1'] = generate_password(32, 3);
				$key['expiration_date'] = date("U") + $key_rotation;
				$key_updated = true;
			}

			// save the new key if modified or created
			if ($key_updated) {

				$array['sessiontalk_keys'][0] = $key;

				$p = new permissions();
				$p->add('sessiontalk_key_add', 'temp');
				$p->add('sessiontalk_key_edit', 'temp');

				// save the data
				$database = new database();
				$database->app_name = 'sessiontalk';
				$database->app_uuid = '85774108-716c-46cb-a34b-ce80b212bc82';
				$database->save($array);
				unset($array);
			}

			// generate the stateless self-expiring password
			$plaintext = $this->credentials['username'] . "@" . $this->credentials['expiration'];

			// Configure openssl
			$cipher = "AES-128-CBC";
			$iv_length = openssl_cipher_iv_length($cipher);

			$iv = random_bytes($iv_length);
			$password = openssl_encrypt($plaintext, $cipher, $key['key1'], $options = 0, $iv);
			$this->credentials['password'] = base64_url_encode($iv . $password);

			// $password_decoded = base64_url_decode($qr['password']);
			// $iv_decoded = substr($password_decoded, 0, 16);
			// $password_split = substr($password_decoded, 16);
			// $original_plaintext = openssl_decrypt($password_split, $cipher, $key['key1'], $options = 0, $iv_decoded);

			$this->credentials['mobile'] = "scsc:" . $this->credentials['username'] . ":" . $this->credentials['password'];
			if (strlen($this->credentials['providerid']) > 0) {
				$this->credentials['mobile'] .= ":" . $this->credentials['providerid'];
			}
			$this->credentials['windows'] = "ms-appinstaller:?source=";
			$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
			$url = $protocol . $_SERVER['HTTP_HOST'] . "/app/sessiontalk/?".$_SESSION['sessiontalk']['windows_softphone_name']['text'].".appinstaller";
			$this->credentials['windows'] .= $url;
			//$this->credentials['windows'] .= $this->settings['windows_softphone_url']['text'];
			$this->credentials['windows'] .= "&activationUri=scsc:?username=";
			$this->credentials['windows'] .= $this->credentials['username'];
			if (strlen($this->credentials['providerid']) > 0) {
				$this->credentials['windows'] .= ":" . $this->credentials['providerid'];
			}
			$this->credentials['windows'] .= "%26password=";
			$this->credentials['windows'] .= $this->credentials['password'];
			$this->credentials['qr_image'] = $this->render_qr();
			return $this->credentials;
		}

		public function render_qr() {
			//stream the file
			$qr_content = html_entity_decode( $this->credentials['mobile'], ENT_QUOTES, 'UTF-8' );

			require_once 'resources/qr_code/QRErrorCorrectLevel.php';
			require_once 'resources/qr_code/QRCode.php';
			require_once 'resources/qr_code/QRCodeImage.php';

			try {
				$code = new QRCode (- 1, QRErrorCorrectLevel::H);
				$code->addData($qr_content);
				$code->make();

				$img = new QRCodeImage ($code, 420, 420, 50);
				$img->draw();
				$image = $img->getImage();
				$img->finish();
			}
			catch (Exception $error) {
				return $error;
			}
			return $image;
		}

		public function get_activations() {
			// Count Devices for this extension
			// Not a perfect method, if you have manually added the same line to multiple devices it is still counted.
			// Also if you add the same line multiple times to a single device for some reason it will still be counted.
			$sql = "SELECT count(*) FROM v_devices as d ";
			$sql .= "JOIN v_device_lines as l ON d.device_uuid = l.device_uuid ";
			$sql .= "WHERE l.user_id = :extension ";
			$sql .= "AND l.server_address = :domain_name ";
			$sql .= "AND d.device_vendor = 'sessiontalk' ";
			$sql .= "AND l.enabled = 'true' ";
			$parameters['extension'] = $this->extension['extension'];
			$parameters['domain_name'] = $this->domain_name;
			$database = new database();
			$activations = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
			return $activations;
		}

		public function send_welcome($app_type = 'all') {

			$reset_button = email_button(strtoupper($text['label-reset_password']), $reset_link, ($_SESSION['theme']['button_background_color_email']['text'] ? $_SESSION['theme']['button_background_color_email']['text'] : '#2e82d0'), ($_SESSION['theme']['button_text_color_email']['text'] ? $_SESSION['theme']['button_text_color_email']['text'] : '#ffffff'));
			$logo_full = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPoAAABGCAYAAADl5IkzAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyJpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMy1jMDExIDY2LjE0NTY2MSwgMjAxMi8wMi8wNi0xNDo1NjoyNyAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENTNiAoV2luZG93cykiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6NjAzQjkyMEYxMzA5MTFFNEJCMEVBNTk1RkYzM0FEMjciIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6NjAzQjkyMTAxMzA5MTFFNEJCMEVBNTk1RkYzM0FEMjciPiA8eG1wTU06RGVyaXZlZEZyb20gc3RSZWY6aW5zdGFuY2VJRD0ieG1wLmlpZDo2MDNCOTIwRDEzMDkxMUU0QkIwRUE1OTVGRjMzQUQyNyIgc3RSZWY6ZG9jdW1lbnRJRD0ieG1wLmRpZDo2MDNCOTIwRTEzMDkxMUU0QkIwRUE1OTVGRjMzQUQyNyIvPiA8L3JkZjpEZXNjcmlwdGlvbj4gPC9yZGY6UkRGPiA8L3g6eG1wbWV0YT4gPD94cGFja2V0IGVuZD0iciI/PufA528AAFJVSURBVHja7H0HnBRV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';
			$logo_shield = 'data:image/png;base64,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';

		//get email template from db
			$sql = "select template_subject, template_body from v_email_templates ";
			$sql .= "where template_language = :template_language ";
			$sql .= "and (domain_uuid = :domain_uuid or domain_uuid is null) ";
			$sql .= "and template_category = 'sessiontalk' ";
			$sql .= "and template_subcategory = :app_type ";
			$sql .= "and template_type = 'html' ";
			$sql .= "and template_enabled = 'true' ";
			$parameters['template_language'] = $_SESSION['domain']['language']['code'];
			$parameters['domain_uuid'] = $this->domain_uuid;
			$parameters['app_type'] = $app_type;
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if (is_array($row)) {
				$email_subject = $row['template_subject'];
				$email_body = $row['template_body'];
			}
			unset($sql, $parameters, $row);

		//replace variables in email body
			$email_body = str_replace('${windows-softphone-link}', $this->credentials['windows'], $email_body);
			$email_body = str_replace('${reset_button}', $reset_button, $email_body);
			$email_body = str_replace('${logo_full}', $logo_full, $email_body);
			$email_body = str_replace('${logo_shield}', $logo_shield, $email_body);
			$email_body = str_replace('${domain}', $this->domain_name, $email_body);

		//send email
			if (send_email($email, $email_subject, $email_body)) {
				//email sent
					message::add($text['message-reset_link_sent'], 'positive', 2500);
			}
			else {
				//email failed
					message::add($eml_error, 'negative', 5000);
			}
		}

		public static function generate_mac() {
			$mac = '2222';
			$chars = '';
			$chars .= "0123456789";
			$chars .= "abcdef";
			for ($i = 0; $i < 8; $i++) {
				$mac .= $chars[random_int(0, strlen($chars)-1)];
			}
			return $mac;
		}

		public static function get_vendor($mac) {
			// use the mac address to find the vendor
			$mac = preg_replace('#[^a-fA-F0-9./]#', '', $mac);
			$mac = strtolower($mac);
			switch (substr($mac, 0, 6)) {
				case "00085d":
					$device_vendor = "aastra";
					break;
				case "001873":
					$device_vendor = "cisco";
					break;
				case "a44c11":
					$device_vendor = "cisco";
					break;
				case "0021A0":
					$device_vendor = "cisco";
					break;
				case "30e4db":
					$device_vendor = "cisco";
					break;
				case "002155":
					$device_vendor = "cisco";
					break;
				case "68efbd":
					$device_vendor = "cisco";
					break;
				case "000b82":
					$device_vendor = "grandstream";
					break;
				case "00177d":
					$device_vendor = "konftel";
					break;
				case "00045a":
					$device_vendor = "linksys";
					break;
				case "000625":
					$device_vendor = "linksys";
					break;
				case "000e08":
					$device_vendor = "linksys";
					break;
				case "08000f":
					$device_vendor = "mitel";
					break;
				case "0080f0":
					$device_vendor = "panasonic";
					break;
				case "0004f2":
					$device_vendor = "polycom";
					break;
				case "00907a":
					$device_vendor = "polycom";
					break;
				case "64167f":
					$device_vendor = "polycom";
					break;
				case "000413":
					$device_vendor = "snom";
					break;
				case "001565":
					$device_vendor = "yealink";
					break;
				case "805ec0":
					$device_vendor = "yealink";
					break;
				case "00268B":
					$device_vendor = "escene";
					break;
				case "001fc1":
					$device_vendor = "htek";
					break;
				case "0C383E":
					$device_vendor = "fanvil";
					break;
				case "7c2f80":
					$device_vendor = "gigaset";
					break;
				case "14b370":
					$device_vendor = "gigaset";
					break;
				case "002104":
					$device_vendor = "gigaset";
					break;
				case "bcc342":
					$device_vendor = "panasonic";
					break;
				case "080023":
					$device_vendor = "panasonic";
					break;
				case "0080f0":
					$device_vendor = "panasonic";
					break;
				default:
					$device_vendor = "";
			}
			return $device_vendor;
		}

		public function get_template_dir() {
			// set the default template directory
			if (PHP_OS == "Linux") {
				// set the default template dir
				if (strlen($this->template_dir) == 0) {
					if (file_exists('/etc/fusionpbx/resources/templates/provision')) {
						$this->template_dir = '/etc/fusionpbx/resources/templates/provision';
					} else {
						$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
					}
				}
			} elseif (PHP_OS == "FreeBSD") {
				// if the FreeBSD port is installed use the following paths by default.
				if (file_exists('/usr/local/etc/fusionpbx/resources/templates/provision')) {
					if (strlen($this->template_dir) == 0) {
						$this->template_dir = '/usr/local/etc/fusionpbx/resources/templates/provision';
					} else {
						$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
					}
				} else {
					if (strlen($this->template_dir) == 0) {
						$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
					} else {
						$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
					}
				}
			} elseif (PHP_OS == "NetBSD") {
				// set the default template_dir
				if (strlen($this->template_dir) == 0) {
					$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
				}
			} elseif (PHP_OS == "OpenBSD") {
				// set the default template_dir
				if (strlen($this->template_dir) == 0) {
					$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
				}
			} else {
				// set the default template_dir
				if (strlen($this->template_dir) == 0) {
					$this->template_dir = $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . '/resources/templates/provision';
				}
			}

			// check to see if the domain name sub directory exists
			if (is_dir($this->template_dir . "/" . $_SESSION["domain_name"])) {
				$this->template_dir = $this->template_dir . "/" . $_SESSION["domain_name"];
			}

			// return the template directory
			return $this->template_dir;
		}

		/**
		 * delete records
		 */
		public function delete($records) {

			// assign private variables
			$this->permission_prefix = 'device_';
			$this->list_page = 'devices.php';
			$this->table = 'devices';
			$this->uuid_prefix = 'device_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records

				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							$array['device_settings'][$x]['device_uuid'] = $record['uuid'];
							$array['device_lines'][$x]['device_uuid'] = $record['uuid'];
							$array['device_keys'][$x]['device_uuid'] = $record['uuid'];
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {

						// grant temporary permissions
						$p = new permissions();
						$p->add('device_setting_delete', 'temp');
						$p->add('device_line_delete', 'temp');
						$p->add('device_key_delete', 'temp');

						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);

						// revoke temporary permissions
						$p->delete('device_setting_delete', 'temp');
						$p->delete('device_line_delete', 'temp');
						$p->delete('device_key_delete', 'temp');

						// write the provision files
						if (strlen($_SESSION['provision']['path']['text']) > 0) {
							$prov = new provision();
							$prov->domain_uuid = $_SESSION['domain_uuid'];
							$response = $prov->write();
						}

						// set message
						message::add($text['message-delete']);
					}
					unset($records);
				}
			}
		}

		public function delete_lines($records) {
			// assign private variables
			$this->permission_prefix = 'device_line_';
			$this->table = 'device_lines';
			$this->uuid_prefix = 'device_line_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// filter out unchecked st_device lines, build delete array
					$x = 0;
					foreach ($records as $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							$array[$this->table][$x]['device_uuid'] = $this->device_uuid;
							$x ++;
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {
						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);
					}
					unset($records);
				}
			}
		}

		public function delete_keys($records) {
			// assign private variables
			$this->permission_prefix = 'device_key_';
			$this->table = 'device_keys';
			$this->uuid_prefix = 'device_key_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// filter out unchecked st_device keys, build delete array
					$x = 0;
					foreach ($records as $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							$array[$this->table][$x]['device_uuid'] = $this->device_uuid;
							$x ++;
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {
						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);
					}
					unset($records);
				}
			}
		}

		public function delete_settings($records) {
			// assign private variables
			$this->permission_prefix = 'device_setting_';
			$this->table = 'device_settings';
			$this->uuid_prefix = 'device_setting_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// filter out unchecked st_device settings, build delete array
					$x = 0;
					foreach ($records as $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							$array[$this->table][$x]['device_uuid'] = $this->device_uuid;
							$x ++;
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {
						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);
					}
					unset($records);
				}
			}
		}

		public function delete_vendors($records) {

			// assign private variables
			$this->permission_prefix = 'device_vendor_';
			$this->list_page = 'device_vendors.php';
			$this->tables[] = 'device_vendors';
			$this->tables[] = 'device_vendor_functions';
			$this->tables[] = 'device_vendor_function_groups';
			$this->uuid_prefix = 'device_vendor_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							foreach ($this->tables as $table) {
								$array[$table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							}
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {

						// grant temporary permissions
						$p = new permissions();
						$p->add('device_vendor_function_delete', 'temp');
						$p->add('device_vendor_function_group_delete', 'temp');

						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);

						// revoke temporary permissions
						$p->delete('device_vendor_function_delete', 'temp');
						$p->delete('device_vendor_function_group_delete', 'temp');

						// set message
						message::add($text['message-delete']);
					}
					unset($records);
				}
			}
		}

		public function delete_vendor_functions($records) {

			// assign private variables
			$this->permission_prefix = 'device_vendor_function_';
			$this->list_page = 'device_vendor_edit.php';
			$this->tables[] = 'device_vendor_functions';
			$this->tables[] = 'device_vendor_function_groups';
			$this->uuid_prefix = 'device_vendor_function_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate('/app/devices/device_vendor_functions.php')) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page.'?id='.$this->device_vendor_uuid);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							foreach ($this->tables as $table) {
								$array[$table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							}
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {

						// grant temporary permissions
						$p = new permissions();
						$p->add('device_vendor_function_group_delete', 'temp');

						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);

						// revoke temporary permissions
						$p->delete('device_vendor_function_group_delete', 'temp');

						// set message
						message::add($text['message-delete']);
					}
					unset($records);
				}
			}
		}

		public function delete_profiles($records) {

			// assign private variables
			$this->permission_prefix = 'device_profile_';
			$this->list_page = 'device_profiles.php';
			$this->tables[] = 'device_profiles';
			$this->tables[] = 'device_profile_keys';
			$this->tables[] = 'device_profile_settings';
			$this->uuid_prefix = 'device_profile_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							foreach ($this->tables as $table) {
								$array[$table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
							}
						}
					}

					// delete the checked rows
					if (is_array($array) && @sizeof($array) != 0) {

						// grant temporary permissions
						$p = new permissions();
						$p->add('device_profile_key_delete', 'temp');
						$p->add('device_profile_setting_delete', 'temp');

						// execute delete
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);

						// revoke temporary permissions
						$p->delete('device_profile_key_delete', 'temp');
						$p->delete('device_profile_setting_delete', 'temp');

						// set message
						message::add($text['message-delete']);
					}
					unset($records);
				}
			}
		}

		public function delete_profile_keys($records) {

			// assign private variables
			$this->permission_prefix = 'device_profile_key_';
			$this->list_page = 'device_profile_edit.php?id=' . $this->device_profile_uuid;
			$this->table = 'device_profile_keys';
			$this->uuid_prefix = 'device_profile_key_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
						}
					}

					// execute delete
					if (is_array($array) && @sizeof($array) != 0) {
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);
					}
					unset($records);
				}
			}
		}

		public function delete_profile_settings($records) {

			// assign private variables
			$this->permission_prefix = 'device_profile_setting_';
			$this->list_page = 'device_profile_edit.php?id=' . $this->device_profile_uuid;
			$this->table = 'device_profile_settings';
			$this->uuid_prefix = 'device_profile_setting_';

			if (permission_exists($this->permission_prefix . 'delete')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// delete multiple records
				if (is_array($records) && @sizeof($records) != 0) {

					// build the delete array
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
						}
					}

					// execute delete
					if (is_array($array) && @sizeof($array) != 0) {
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->delete($array);
						unset($array);
					}
					unset($records);
				}
			}
		}

		/**
		 * toggle records
		 */
		public function toggle($records) {

			// assign private variables
			$this->permission_prefix = 'device_';
			$this->list_page = 'devices.php';
			$this->table = 'devices';
			$this->uuid_prefix = 'device_';
			$this->toggle_field = 'device_enabled';
			$this->toggle_values = [
				'true',
				'false'
			];

			if (permission_exists($this->permission_prefix . 'edit')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// toggle the checked records
				if (is_array($records) && @sizeof($records) != 0) {

					// get current toggle state
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$uuids[] = "'" . $record['uuid'] . "'";
						}
					}
					if (is_array($uuids) && @sizeof($uuids) != 0) {
						$sql = "select " . $this->uuid_prefix . "uuid as uuid, " . $this->toggle_field . " as toggle from v_" . $this->table . " ";
						$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
						$sql .= "and " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$database = new database();
						$rows = $database->select($sql, $parameters, 'all');
						if (is_array($rows) && @sizeof($rows) != 0) {
							foreach ($rows as $row) {
								$states[$row['uuid']] = $row['toggle'];
							}
						}
						unset($sql, $parameters, $rows, $row);
					}

					// build update array
					$x = 0;
					foreach ($states as $uuid => $state) {
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
						$array[$this->table][$x][$this->toggle_field] = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
						$x ++;
					}

					// save the changes
					if (is_array($array) && @sizeof($array) != 0) {

						// save the array
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->save($array);
						unset($array);

						// write the provision files
						if (strlen($_SESSION['provision']['path']['text']) > 0) {
							$prov = new provision();
							$prov->domain_uuid = $_SESSION['domain_uuid'];
							$response = $prov->write();
						}

						// set message
						message::add($text['message-toggle']);
					}
					unset($records, $states);
				}
			}
		}

		public function toggle_vendors($records) {

			// assign private variables
			$this->permission_prefix = 'device_vendor_';
			$this->list_page = 'device_vendors.php';
			$this->table = 'device_vendors';
			$this->uuid_prefix = 'device_vendor_';
			$this->toggle_field = 'enabled';
			$this->toggle_values = [
				'true',
				'false'
			];

			if (permission_exists($this->permission_prefix . 'edit')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// toggle the checked records
				if (is_array($records) && @sizeof($records) != 0) {

					// get current toggle state
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$uuids[] = "'" . $record['uuid'] . "'";
						}
					}
					if (is_array($uuids) && @sizeof($uuids) != 0) {
						$sql = "select " . $this->uuid_prefix . "uuid as uuid, " . $this->toggle_field . " as toggle from v_" . $this->table . " ";
						$sql .= "where " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
						$database = new database();
						$rows = $database->select($sql, '', 'all');
						if (is_array($rows) && @sizeof($rows) != 0) {
							foreach ($rows as $row) {
								$states[$row['uuid']] = $row['toggle'];
							}
						}
						unset($sql, $parameters, $rows, $row);
					}

					// build update array
					$x = 0;
					foreach ($states as $uuid => $state) {
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
						$array[$this->table][$x][$this->toggle_field] = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
						$x ++;
					}

					// save the changes
					if (is_array($array) && @sizeof($array) != 0) {

						// save the array
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->save($array);
						unset($array);

						// set message
						message::add($text['message-toggle']);
					}
					unset($records, $states);
				}
			}
		}

		public function toggle_vendor_functions($records) {

			// assign private variables
			$this->permission_prefix = 'device_vendor_function_';
			$this->list_page = 'device_vendor_edit.php';
			$this->table = 'device_vendor_functions';
			$this->uuid_prefix = 'device_vendor_function_';
			$this->toggle_field = 'enabled';
			$this->toggle_values = [
				'true',
				'false'
			];

			if (permission_exists($this->permission_prefix . 'edit')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate('/app/devices/device_vendor_functions.php')) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page.'?id='.$this->device_vendor_uuid);
				// exit;
				// }

				// toggle the checked records
				if (is_array($records) && @sizeof($records) != 0) {

					// get current toggle state
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$uuids[] = "'" . $record['uuid'] . "'";
						}
					}
					if (is_array($uuids) && @sizeof($uuids) != 0) {
						$sql = "select " . $this->uuid_prefix . "uuid as uuid, " . $this->toggle_field . " as toggle from v_" . $this->table . " ";
						$sql .= "where " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
						$database = new database();
						$rows = $database->select($sql, '', 'all');
						if (is_array($rows) && @sizeof($rows) != 0) {
							foreach ($rows as $row) {
								$states[$row['uuid']] = $row['toggle'];
							}
						}
						unset($sql, $parameters, $rows, $row);
					}

					// build update array
					$x = 0;
					foreach ($states as $uuid => $state) {
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
						$array[$this->table][$x][$this->toggle_field] = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
						$x ++;
					}

					// save the changes
					if (is_array($array) && @sizeof($array) != 0) {

						// save the array
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->save($array);
						unset($array);

						// set message
						message::add($text['message-toggle']);
					}
					unset($records, $states);
				}
			}
		}

		public function toggle_profiles($records) {

			// assign private variables
			$this->permission_prefix = 'device_profile_';
			$this->list_page = 'device_profiles.php';
			$this->table = 'device_profiles';
			$this->uuid_prefix = 'device_profile_';
			$this->toggle_field = 'device_profile_enabled';
			$this->toggle_values = [
				'true',
				'false'
			];

			if (permission_exists($this->permission_prefix . 'edit')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// toggle the checked records
				if (is_array($records) && @sizeof($records) != 0) {

					// get current toggle state
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$uuids[] = "'" . $record['uuid'] . "'";
						}
					}
					if (is_array($uuids) && @sizeof($uuids) != 0) {
						$sql = "select " . $this->uuid_prefix . "uuid as uuid, " . $this->toggle_field . " as toggle from v_" . $this->table . " ";
						$sql .= "where " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
						$database = new database();
						$rows = $database->select($sql, '', 'all');
						if (is_array($rows) && @sizeof($rows) != 0) {
							foreach ($rows as $row) {
								$states[$row['uuid']] = $row['toggle'];
							}
						}
						unset($sql, $parameters, $rows, $row);
					}

					// build update array
					$x = 0;
					foreach ($states as $uuid => $state) {
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
						$array[$this->table][$x][$this->toggle_field] = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
						$x ++;
					}

					// save the changes
					if (is_array($array) && @sizeof($array) != 0) {

						// save the array
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->save($array);
						unset($array);

						// set message
						message::add($text['message-toggle']);
					}
					unset($records, $states);
				}
			}
		}

		/**
		 * copy records
		 */
		public function copy_profiles($records) {

			// assign private variables
			$this->permission_prefix = 'device_profile_';
			$this->list_page = 'device_profiles.php';
			$this->table = 'device_profiles';
			$this->uuid_prefix = 'device_profile_';

			if (permission_exists($this->permission_prefix . 'add')) {

				// add multi-lingual support
				$language = new text();
				$text = $language->get();

				// validate the token
				// $token = new token;
				// if (!$token->validate($_SERVER['PHP_SELF'])) {
				// message::add($text['message-invalid_token'],'negative');
				// header('Location: '.$this->list_page);
				// exit;
				// }

				// copy the checked records
				if (is_array($records) && @sizeof($records) != 0) {

					// get checked records
					foreach ($records as $x => $record) {
						if ($record['checked'] == 'true' && is_uuid($record['uuid'])) {
							$uuids[] = "'" . $record['uuid'] . "'";
						}
					}

					// create insert array from existing data
					if (is_array($uuids) && @sizeof($uuids) != 0) {
						$sql = "select * from v_" . $this->table . " ";
						$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
						$sql .= "and " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$database = new database();
						$rows = $database->select($sql, $parameters, 'all');
						if (is_array($rows) && @sizeof($rows) != 0) {
							$y = $z = 0;
							foreach ($rows as $x => $row) {
								$primary_uuid = uuid();

								// copy data
								$array[$this->table][$x] = $row;

								// overwrite
								$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $primary_uuid;
								$array[$this->table][$x]['device_profile_description'] = trim($row['device_profile_description'] . ' (' . $text['label-copy'] . ')');

								// keys sub table
								$sql_2 = "select * from v_device_profile_keys ";
								$sql_2 .= "where device_profile_uuid = :device_profile_uuid ";
								$sql_2 .= "order by ";
								$sql_2 .= "case profile_key_category ";
								$sql_2 .= "when 'line' then 1 ";
								$sql_2 .= "when 'memort' then 2 ";
								$sql_2 .= "when 'programmable' then 3 ";
								$sql_2 .= "when 'expansion' then 4 ";
								$sql_2 .= "else 100 end, ";
								$sql_2 .= "profile_key_id asc ";
								$parameters_2['device_profile_uuid'] = $row['device_profile_uuid'];
								$database = new database();
								$rows_2 = $database->select($sql_2, $parameters_2, 'all');
								if (is_array($rows_2) && @sizeof($rows_2) != 0) {
									foreach ($rows_2 as $row_2) {

										// copy data
										$array['device_profile_keys'][$y] = $row_2;

										// overwrite
										$array['device_profile_keys'][$y]['device_profile_key_uuid'] = uuid();
										$array['device_profile_keys'][$y]['device_profile_uuid'] = $primary_uuid;

										// increment
										$y ++;
									}
								}
								unset($sql_2, $parameters_2, $rows_2, $row_2);

								// settings sub table
								$sql_3 = "select * from v_device_profile_settings where device_profile_uuid = :device_profile_uuid";
								$parameters_3['device_profile_uuid'] = $row['device_profile_uuid'];
								$database = new database();
								$rows_3 = $database->select($sql_3, $parameters_3, 'all');
								if (is_array($rows_3) && @sizeof($rows_3) != 0) {
									foreach ($rows_3 as $row_3) {

										// copy data
										$array['device_profile_settings'][$z] = $row_3;

										// overwrite
										$array['device_profile_settings'][$z]['device_profile_setting_uuid'] = uuid();
										$array['device_profile_settings'][$z]['device_profile_uuid'] = $primary_uuid;

										// increment
										$z ++;
									}
								}
								unset($sql_3, $parameters_3, $rows_3, $row_3);
							}
						}
						unset($sql, $parameters, $rows, $row);
					}

					// save the changes and set the message
					if (is_array($array) && @sizeof($array) != 0) {

						// grant temporary permissions
						$p = new permissions();
						$p->add('device_profile_key_add', 'temp');
						$p->add('device_profile_setting_add', 'temp');

						// save the array
						$database = new database();
						$database->app_name = $this->app_name;
						$database->app_uuid = $this->app_uuid;
						$database->save($array);
						unset($array);

						// revoke temporary permissions
						$p->delete('device_profile_key_add', 'temp');
						$p->delete('device_profile_setting_add', 'temp');

						// set message
						message::add($text['message-copy']);
					}
					unset($records);
				}
			}
		} // method
	} // class

?>
