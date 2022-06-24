<?php
	
# Ignore inline messages (via @)
if ($v->via_bot) die;

# Start Fallout Shelter class
$fsv = new FalloutShelter($db, $user['lang']);
# Set the max number of vaults that user can have in their work space
$max_vaults = 3;

# Private chat with Bot
if ($v->chat_type == 'private') {
	if (is_a($db, 'Database') && $db->configs['database']['status']) {
		if ($user['status'] !== 'started') $db->setStatus($v->user_id, 'started');
		if (!isset($user['settings']['deleteMessages'])) $user['settings']['deleteMessages'] = false;
		if ($user['settings']['deleteMessages']) {
			if (!$v->query_id) $bot->deleteMessage($v->chat_id, $v->message_id);
		}
	}
	
	# Actions
	if (is_a($db, 'Database') && $db->configs['redis']['status']) {
		$action = $db->rget('FSV-action-' . $v->user_id);
		# Cancel actions
		if ($v->command == 'cancel' || strpos($v->query_data, 'cancel') === 0) {
			$db->rdel('FSV-action' . $v->user_id);
			if ($v->command) {
				$bot->sendMessage($v->chat_id, $tr->getTranslation('commandCancelled'));
				die;
			} else {
				if (strpos($v->query_data, 'cancel|') === 0) {
					$v->query_data = str_replace('cancel|', '', $v->query_data);
				} else {
					$t = $v->text . PHP_EOL . PHP_EOL . $tr->getTranslation('commandCancelled');
					$v->entities[] = ['type' => 'bold', 'offset' => mb_strlen($v->text, 'UTF-8'), 'length' => round(mb_strlen($t, 'UTF-8') - mb_strlen($v->text, 'UTF-8'))];
					$bot->editText($v->chat_id, $v->message_id, $t, [], $v->entities);
					$bot->answerCBQ($v->query_id);
					die;
				}
			}
		}
		# Actions
		elseif ($action) {
			# Upload Cloud file
			if ($action == 'uploadCloudFile' and isset($v->document_id)) {
				$db->rdel('FSV-action-' . $v->user_id);
				if (substr($v->document_name, -4) == '.sav') {
					if ($v->document_size >= 1048576) {
						$t = $tr->getTranslation('importFileOverSize', [1]);
					} else {
						$newVaultFile = 'data/vault-cloud-' . $v->user_id . '.sav';
						copy('https://api.telegram.org/file/bot' . $bot->token . '/' . $bot->getFile($v->document_id)['result']['file_path'], $newVaultFile);
						if (file_exists($newVaultFile) && filesize($newVaultFile)) {
							$fsv->decryptVault($newVaultFile, $newVaultFile . '.json');
							if (file_exists($newVaultFile . '.json') && filesize($newVaultFile . '.json')) {
								$vault = json_decode(file_get_contents($newVaultFile . '.json'), true);
								if (isset($vault['vault']['VaultName'])) {
									$user['settings']['vaults'][$vault['vault']['VaultName']] = $vault;
									$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
									$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/188c0aa54aec2a63995b3.jpg') .
									$bot->bold('💾 ' . $tr->getTranslation('vaultTitle', [$vault['vault']['VaultName']]));
									$buttons[][] = $bot->createInlineButton($tr->getTranslation('myVaults'), 'myVaults');
									$buttons[][] = $bot->createInlineButton('◀️', 'start');
									if ($vault['vault']['VaultMode'] !== 'Normal') $t .= ' 💥';
									$t .= PHP_EOL . $bot->italic($tr->getTranslation('importFileDone'));
								}
								unlink($newVaultFile . '.json');
							}
							unlink($newVaultFile);
						}
						if (!$t) $t = $tr->getTranslation('importFileCorrupt');
					}
				} else {
					$t = $tr->getTranslation('importFileWrongExtension');
				}
				$db->rset('FSV-action-' . $v->user_id, $action, (60 * 5));
				$bot->sendMessage($v->chat_id, $t, $buttons);
				die;
			}
			# Delete unknown actions without interrupt the script
			else {
				$db->rdel('FSV-action-' . $v->user_id);
			}
		}
	}
	
	# Decrypt save Test
	if ($v->command == 'test' && $v->isAdmin()) {
		$original = 'data/vault.sav';
		$json = $original . '.json';
		$t = $bot->code(substr(json_encode($fsv->decryptVault($original, $json), JSON_PRETTY_PRINT), 0, 4096));
		$bot->sendMessage($v->chat_id, $t);
	}
	# Start message
	elseif ($v->command == 'start' || $v->query_data == 'start') {
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/de736bf1c647b7d1e87dc.jpg') . 
		$tr->getTranslation('startMessage');
		$buttons[] = [
			$bot->createInlineButton($tr->getTranslation('newVault'), 'newVault'),
			$bot->createInlineButton($tr->getTranslation('myVaults'), 'myVaults')
		];
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('survivalGuide'), 'survivalGuide');
		$buttons[] = [
			$bot->createInlineButton($tr->getTranslation('buttonSettings'), 'settings'),
			$bot->createInlineButton($tr->getTranslation('buttonCredits'), 'about')
		];
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# About message
	elseif (in_array($v->command, ['about', 'help']) || $v->query_data == 'about') {
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/45fc2c7792df6315322d2.jpg') .
		$tr->getTranslation('aboutMessage', [explode('-', phpversion(), 2)[0], 'https://www.paypal.com/donate/?hosted_button_id=3NJZ7EQDFSG7J', 'https://hetzner.cloud/?ref=tQoUeYbvIstA', 'Hetzner.cloud']);
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# New vault
	elseif (strpos($v->query_data, 'newVault') === 0) {
		if ($v->isAdmin || (isset($user['settings']['vaults']) && count($user['settings']['vaults']) >= $max_vaults)) {
			$bot->answerCBQ($v->query_id, '🚚 Too many vaults!');
			die;
		}
		if (strpos($v->query_data, 'newVault_') === 0) {
			$num = substr($v->query_data, 9, 3);
		} else {
			$num = rand(0, 999);
		}
		$buttons = $fsv->vaultNumKeyboard($bot, $num);
		$buttons[] = [
			$bot->createInlineButton($tr->getTranslation('buttonGenerate'), 'newVaultRandom'),
			$bot->createInlineButton($tr->getTranslation('buttonSave'), 'createVault:' . $num),
			$bot->createInlineButton($tr->getTranslation('buttonCloud'), 'cloudImport')
		];
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/94318d4956ff0b7cd98dd.jpg') .
		$tr->getTranslation('createNewVault');
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Cloud import
	elseif ($v->query_data == 'cloudImport') {
		if (!$v->isAdmin()) {
			$cbt = '⚠️ Not available!';
		} else {
			$buttons[][] = $bot->createInlineButton('◀️', 'cancel|newVault');
			$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/94318d4956ff0b7cd98dd.jpg') .
			$tr->getTranslation('importFile');
			if (is_a($db, 'Database') && $db->configs['redis']['status']) $db->rset('FSV-action-' . $v->user_id, 'uploadCloudFile', (60 * 5));
		}
		if ($v->query_id) {
			if ($t) $bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id, $cbt, true);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Create a new vault
	elseif (strpos($v->query_data, 'createVault:') === 0) {
		if ($v->isAdmin || (isset($user['settings']['vaults']) && count($user['settings']['vaults']) >= $max_vaults)) {
			$bot->answerCBQ($v->query_id, '🚚 Too many vaults!');
			die;
		}
		$id = str_replace('createVault:', '', $v->query_data);
		if (!$id) {
			$bot->answerCBQ($v->query_id);
			die;
		}
		$bot->answerCBQ($v->query_id, $tr->getTranslation('loading'));
		$bot->editText($v->chat_id, $v->message_id, $bot->text_link('&#8203;', 'https://telegra.ph/file/28a13f7fc8d33b7a6c34f.jpg') . $tr->getTranslation('loading'), [], 'def', false);
		# Create Fallout Shelter random data
		$vault = [];
		$vault['timeMgr'] = [
			'gameTime' => 000000.00,
			'questTime' => 000000,
			'time' => time(),
			'timeSaveDate' => time(),
			'timeGameBegin' => time()
		];
		$vault['localNotificationMgr'] = [
			'UniqueIDS' => [],
			'AndroidNotifications' => []
		];
		$vault['taskMgr'] = [];
		$vault['ratingMgr'] = [];
		$vault['specialTheme'] = [
			'themeByRoomType' => [
				'LivingQuarters' => '',
				'Cafeteria' => ''
			],
			'eventsThemes' => [],
			'lastOverallTheme' => 'None'
		];
		$vault['vault'] = [
			'VaultName' => $id,
			'VaultMode' => 'Normal'
		];
		$vault['deviceName'] = '@FalloutShelterVaultBot on Telegram';
		$vault['appVersion'] = ' 1.13.19';
		$vault['versionCount'] = 13516;
		$user['settings']['vaults'][$id] = $vault;
		$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
		$v->query_data = 'myVault_' . $id;
		require(__FILE__);
		die;
	}
	# My vaults
	elseif ($v->query_data == 'myVaults') {
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/188c0aa54aec2a63995b3.jpg') .
		$bot->bold($tr->getTranslation('vaultsList')) . PHP_EOL;
		if (isset($user['settings']['vaults']) && !empty($user['settings']['vaults'])) {
			foreach ($user['settings']['vaults'] as $id => $vault) {
				if ($vault['vault']['VaultMode'] !== 'Normal') {
					$is_survival = ' 💥';
				} else {
					$is_survival = '';
				}
				$t .= '- ' . $tr->getTranslation('vaultTitle', [$vault['vault']['VaultName']]) . $is_survival . PHP_EOL;
				$buttons[][] = $bot->createInlineButton('💾 ' . $id, 'myVault_' . $id);
			}
		} else {
			$t .= $bot->italic($tr->getTranslation('vaultsEmpty'));
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Vault info
	elseif (strpos($v->query_data, 'myVault_') === 0) {
		$id = str_replace('myVault_', '', $v->query_data);
		if (isset($user['settings']['vaults'][$id]) && !empty($user['settings']['vaults'][$id])) {
			if ($user['settings']['vaults'][$id]['vault']['VaultMode'] !== "Normal") $survival = ' 💥';
			$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/8b20f7ae80abbfdc804cd.jpg') . '🗄 ' . $bot->bold($tr->getTranslation('vaultTitle', [$id])) . $survival . PHP_EOL .
			$tr->getTranslation('deviceName', [$user['settings']['vaults'][$id]['deviceName']]) . PHP_EOL .
			$tr->getTranslation('versionInfo', [$user['settings']['vaults'][$id]['appVersion']]);
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('buttonStatistics'), 'vaultStats_' . $id);
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('buttonInventory'), 'vaultInventory_' . $id),
				$bot->createInlineButton($tr->getTranslation('buttonDwellers'), 'vaultDwellers_' . $id)
			];
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('buttonDownload'), 'vaultDownload_' . $id),
				$bot->createInlineButton($tr->getTranslation('buttonDelete'), 'deleteVault_' . $id)
			];
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'myVaults');
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Vault stats
	elseif (strpos($v->query_data, 'vaultStats_') === 0) {
		$id = str_replace('vaultStats_', '', $v->query_data);
		if (isset($user['settings']['vaults'][$id]) && !empty($user['settings']['vaults'][$id])) {
			$t = $bot->bold($tr->getTranslation('buttonStatistics')) . PHP_EOL;
			if (!$user['settings']['vaults'][$id]['dwellers']['dwellers']) $user['settings']['vaults'][$id]['dwellers']['dwellers'] = [];
			$t .= $tr->getTranslation('dwellersCount', [count($user['settings']['vaults'][$id]['dwellers']['dwellers'])]);
			if ($user['settings']['vaults'][$id]['dwellerSpawner']['dwellerWaiting']) $t .= ' +' . count($vault['dwellerSpawner']['dwellerWaiting']);
			
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'myVault_' . $id);
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Vault inventory
	elseif (strpos($v->query_data, 'vaultInventory_') === 0) {
		$id = str_replace('vaultInventory_', '', $v->query_data);
		if (isset($user['settings']['vaults'][$id]) && !empty($user['settings']['vaults'][$id])) {
			if (!isset($user['settings']['vaults'][$id]['vault']['inventory']['items'])) $user['settings']['vaults'][$id]['vault']['inventory']['items'] = [];
			$t = $bot->bold($tr->getTranslation('storageCount', [count($user['settings']['vaults'][$id]['vault']['inventory']['items'])]));
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'myVault_' . $id);
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Vault dwellers
	elseif (strpos($v->query_data, 'vaultDwellers_') === 0) {
		$e = explode('_', $v->query_data);
		if (isset($user['settings']['vaults'][$e[1]]) && !empty($user['settings']['vaults'][$e[1]])) {
			$t = $bot->bold($tr->getTranslation('buttonDwellers'));
			$list = [
				'actors' => '',
				'dwellers' => ''
			];
			if (isset($e[2]) && in_array($e[2], array_keys($list))) {
				$list[$e[2]] = '📄';
				if (!$e[3]) $e[3] = 1;
				$max = $e[3] * 20;
				$range = range($max - 20, $max - 1);
				$dwellers = $fsv->sortDwellers($user['settings']['vaults'][$e[1]]['dwellers'][$e[2]]);
				foreach($range as $num) {
					if ($dwellers[$num]) {
						$t .= PHP_EOL . ($num + 1) . ') ' . htmlspecialchars($dwellers[$num]['name'] . ' ' . $dwellers[$num]['lastName']);
						if ($type == "dwellers") $t .= ' | ' . $dwellers[$num]['experience']['currentLevel'];
					}
				}
				if ($e[3] > 1) $sbuttons[] = $bot->createInlineButton('⬅️', 'vaultDwellers_' . $e[1] . '_' . $e[2] . '_' . ($e[3] - 1));
				if ($e[3] < round(count($dwellers) / 20)) $sbuttons[] = $bot->createInlineButton('➡️', 'vaultDwellers_' . $e[1] . '_' . $e[2] . '_' . ($e[3] + 1));
				$t .= round(count($dwellers) / 20);
				if (isset($sbuttons)) $buttons[] = $sbuttons;
			}
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('actors') . $list['actors'], 'vaultDwellers_' . $e[1] . '_actors'),
				$bot->createInlineButton($tr->getTranslation('dwellers') . $list['dwellers'], 'vaultDwellers_' . $e[1] . '_dwellers')
			];
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'myVault_' . $e[1]);
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# Download vault.sav
	elseif (strpos($v->query_data, 'vaultDownload_') === 0) {
		$id = str_replace('vaultDownload_', '', $v->query_data);
		if (isset($user['settings']['vaults'][$id]) && !empty($user['settings']['vaults'][$id])) {
			$bot->answerCBQ($v->query_id, '📤');
			$bot->editText($v->chat_id, $v->message_id, $tr->getTranslation('loading'));
			$json_file = 'data/vault-' . $id . '-' . $v->user_id . '.json';
			$sav_file = 'data/vault-' . $id . '-' . $v->user_id . '.sav';
			file_put_contents($json_file, json_encode($user['settings']['vaults'][$id]));
			$fsv->encryptVault($json_file, $sav_file);
			unlink($json_file);
			$bot->sendDocument($v->chat_id, $bot->createFileInput($sav_file, null, 'vault.sav'), false, false, 'def', false, $bot->createFileInput('data/file_thumb.jpeg'));
			$v->query_data = 'myVault_' . $id;
			require(__FILE__);
			unlink($sav_file);
			die;
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
	}
	# Delete vault
	elseif (strpos($v->query_data, 'deleteVault_') === 0) {
		$e = explode('_', $v->query_data);
		if (isset($user['settings']['vaults'][$e[1]]) && !empty($user['settings']['vaults'][$e[1]])) {
			if (isset($e[2])) {
				unset($user['settings']['vaults'][$e[1]]);
				$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
				$v->query_data = 'myVaults';
				require(__FILE__);
				die;
			} else {
				$t = $tr->getTranslation('areYouSureToDeleteVault');
				$buttons[] = [
					$bot->createInlineButton($tr->getTranslation('yes'), 'deleteVault_' . $e[1] . '_y'),
					$bot->createInlineButton($tr->getTranslation('no'), 'myVault_' . $e[1])
				];
			}
		} else {
			$v->query_data = 'myVaults';
			require(__FILE__);
			die;
		}
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
	}
	# Survival Guide
	elseif ($v->query_data == 'survivalGuide') {
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/28a13f7fc8d33b7a6c34f.jpg') .
		'📖 ' . $fsv->getTranslation('KeyboardMapping_OpenWindowSurvivalGuide');
		$buttons[][] = $bot->createInlineButton('👷🏻‍♂️ ' . $fsv->getTranslation('Tutorial_Header'), 'tutorial');
		$buttons[][] = $bot->createInlineButton('🚶🏻‍♂️ ' . $fsv->getTranslation('Wasteland_Title_Exploring'), 'simulation');
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
		if ($v->query_id) $bot->answerCBQ($v->query_id);
	}
	# Tutorial
	elseif (strpos($v->query_data, 'tutorial') === 0) {
		if (strpos($v->query_data, 'tutorial_') === 0) {
			$tip = substr($v->query_data, 9, 2);
		} else {
			$tip = rand(0, 84);
		}
		if ($tip !== 1) $sbuttons[] = $bot->createInlineButton('⬅️', 'tutorial_' . round($tip - 1));
		$sbuttons[] = $bot->createInlineButton('🔀', 'tutorial');
		if ($tip < 85) $sbuttons[] = $bot->createInlineButton('➡️', 'tutorial_' . round($tip + 1));
		$buttons[] = $sbuttons;
		$buttons[][] = $bot->createInlineButton('◀️', 'survivalGuide');
		if ($tip < 10) $tip = '0' . $tip;
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/28a13f7fc8d33b7a6c34f.jpg') .
		'👷🏻‍♂️ ' . $bot->bold($fsv->getTranslation('Tutorial_Header')) . PHP_EOL .
		$fsv->getTranslation('Tutorial_Tip_' . $tip);
		$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
		if ($v->query_id) $bot->answerCBQ($v->query_id);
	}
	# Simulation (Not available at the moment)
	elseif (strpos($v->query_data, 'simulation') === 0) {
		$events = [
			'speechQuestAlone' => range(1, 12),
			'randomMessage' => range(1, 221),
			'journalMessage' => range(26, 45),
			'creature' => range(1, 27),
			'location' => range(1, 17),
			'npc' => range(1, 10)
		];
		$buttons[][] = $bot->createInlineButton('◀️', 'survivalGuide');
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/22d7b4478d8ca4a951473.jpg') .
		'🚶🏻‍♂️ ' . $bot->bold($fsv->getTranslation('Wasteland_Title_Exploring')) . PHP_EOL .
		'In development...';
		$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
		if ($v->query_id) $bot->answerCBQ($v->query_id);
	}
	# Stop simulation
	elseif ($v->query_data == 'simulation_stop') {
		$db->rdel('FSV-action-' . $v->user_id);
		if ($v->query_id) $bot->answerCBQ($v->query_id, $fsv->getTranslation('Wasteland_Title_Ret_To_Vault'), false);
	}
	# Settings
	elseif (strpos($v->query_data, 'settings') === 0) {
		if (strpos($v->query_data, 'settings-') === 0) {
			$user['settings']['deleteMessages'] = (!$user['settings']['deleteMessages']) ? true : false;
			$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $user['id']]);
		}
		if ($user['settings']['deleteMessages']) {
			$bool_emoji = '✅';
		} else {
			$bool_emoji = '❌';
		}
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('deleteChat', [$bool_emoji]), 'settings-deleteMessages');
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('changeLanguage'), 'lang');
		$buttons[][] = $bot->createInlineButton('◀️', 'start');
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/1a83f7f439cdd4fbbd3a0.jpg') .
		$bot->bold($tr->getTranslation('botSettings'));
		$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
		if ($v->query_id) $bot->answerCBQ($v->query_id);
	}
	# Change language
	elseif ($v->command == 'lang' || $v->query_data == 'lang' || strpos($v->query_data, 'changeLanguage-') === 0) {
		$langnames = [
			'en' => '🇬🇧 English',
			'ar' => '🇩🇿 عربى',
			'es' => '🇪🇸 Español',
			'fr' => '🇫🇷 Français',
			'id' => '🇮🇩 Indonesian',
			'it' => '🇮🇹 Italiano',
			'de' => '🇩🇪 Deutsch',
			'ru' => '🇷🇺 Русский'
		];
		if (strpos($v->query_data, 'changeLanguage-') === 0) {
			$select = str_replace('changeLanguage-', '', $v->query_data);
			if (in_array($select, array_keys($langnames))) {
				$tr->setLanguage($select);
				$user['lang'] = $select;
				$db->query('UPDATE users SET lang = ? WHERE id = ?', [$user['lang'], $user['id']]);
			}
		}
		$langnames[$user['lang']] .= ' ✅';
		$t = $bot->text_link('&#8203;', 'https://telegra.ph/file/1a83f7f439cdd4fbbd3a0.jpg') .
		'🔡 Select your language';
		$formenu = 2;
		$mcount = 0;
		foreach ($langnames as $lang_code => $name) {
			if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
			$buttons[$mcount][] = $bot->createInlineButton($name, 'changeLanguage-' . $lang_code);
		}
		$buttons[][] = $bot->createInlineButton('◀️', 'settings');
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', false);
			$bot->answerCBQ($v->query_id);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons, 'def', false);
		}
	}
	# General stats command
	else {
		if ($v->command) {
			$t = $tr->getTranslation('unknownCommand');
		} elseif (!$v->query_data) {
			$t = $tr->getTranslation('noCommandRun');
		}
		if ($v->query_id) {
			$bot->answerCBQ($v->query_id, $t);
		} else {
			$bot->sendMessage($v->chat_id, $t);
		}
	}
}

?>