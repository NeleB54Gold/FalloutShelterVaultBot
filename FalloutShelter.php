<?php

class FalloutShelter {
	# Database class
	private $db = [];
	# Default user language
	private $lang = 'en';
	# Supported game languages
	private $langs = ['de', 'en', 'es', 'fr', 'it', 'ru'];
	# Translations file
	private $translations_file = 'data/game-translations.json';
	# Translations ID
	private $game_name = 'FalloutShelter';
	
	# Set configs
	public function __construct ($db = [], $lang = null) {
		if (is_a($db, 'Database') && $db->configs['redis']['status']) {
			$this->db = $db;
			$this->redisCheck();
		} else {
			if (!$this->translations = json_decode(file_get_contents($this->translations_file), true)) return ['ok' => 0, 'error_code' => 500, 'description' => 'Unable to get translations data!'];
		}
		if (is_string($lang)) $this->lang = $lang;
	}
	
	# Decrypt vault
	public function decryptVault ($file_name, $to_file_name) {
		if (!file_exists($file_name) || !filesize($file_name)) return false;
		$original_content = file_get_contents($file_name);
		$json_content = $this->decrypt($original_content);
		$json_content = json_encode(json_decode($json_content, true), JSON_PRETTY_PRINT);
		return file_put_contents($to_file_name, $json_content);
	}
	
	# Encrypt vault
	public function encryptVault ($file_name, $to_file_name) {
		if (!file_exists($file_name) || !filesize($file_name)) return false;
		$json_content = file_get_contents($file_name);
		$original_content = $this->encrypt($json_content);
		return file_put_contents($to_file_name, $original_content);
	}
	
	# Generate the PassPhrase to decrypt
	private function generatePassPhrase(string $phrase = "PlayerData") {
		while(strlen($phrase) < 8) {
			$phrase .= $phrase;
		}
		return substr(base64_encode($phrase), 0, 8);
	}
	
	# Decrypt contents
	private function decrypt(string $cipherText) {
		$passPhrase = $this->generatePassPhrase();
		$result = openssl_decrypt(
			base64_decode($cipherText),
			"AES-256-CBC",
			hash_pbkdf2("sha1", $passPhrase, 'tu89geji340t89u2', 1000, 0x20, true),
			OPENSSL_RAW_DATA,
			'tu89geji340t89u2'
		);
		return $result; //substr($result, -1) == "}" ? $result : substr($result, 0, strrpos($result, "}") + 1);
	}

	# Encrypt contents
	private function encrypt(string $textData) {
		$passPhrase = $this->generatePassPhrase();
		return base64_encode(
			openssl_encrypt(
				$textData,
				"AES-256-CBC",
				hash_pbkdf2("sha1", $passPhrase, 'tu89geji340t89u2', 1000, 0x20, true),
				OPENSSL_RAW_DATA, 
				'tu89geji340t89u2'
			)
		);
	}
	
	# Get new vault keyboard
	public function vaultNumKeyboard ($bot, $num = 0) {
		$num_string = substr(number_format($num / 1000, 3), 2);
		$emoji = '️⃣';
		$buttons[] = [
			$bot->createInlineButton($this->prevNum($num_string[0]) . $emoji, 'newVault_' . $this->prevNum($num_string[0]) . $num_string[1] . $num_string[2]),
			$bot->createInlineButton($this->prevNum($num_string[1]) . $emoji, 'newVault_' . $num_string[0] . $this->prevNum($num_string[1]) . $num_string[2]),
			$bot->createInlineButton($this->prevNum($num_string[2]) . $emoji, 'newVault_' . $num_string[0] . $num_string[1] . $this->prevNum($num_string[2]))
		];
		$buttons[] = [
			$bot->createInlineButton($num_string[0] . $emoji, 'newVault_' . $num_string),
			$bot->createInlineButton($num_string[1] . $emoji, 'newVault_' . $num_string),
			$bot->createInlineButton($num_string[2] . $emoji, 'newVault_' . $num_string)
		];
		$buttons[] = [
			$bot->createInlineButton($this->nextNum($num_string[0]) . $emoji, 'newVault_' . $this->nextNum($num_string[0]) . $num_string[1] . $num_string[2]),
			$bot->createInlineButton($this->nextNum($num_string[1]) . $emoji, 'newVault_' . $num_string[0] . $this->nextNum($num_string[1]) . $num_string[2]),
			$bot->createInlineButton($this->nextNum($num_string[2]) . $emoji, 'newVault_' . $num_string[0] . $num_string[1] . $this->nextNum($num_string[2]))
		];
		return $buttons;
	}
	
	# Get the previous number for keyboards
	private function prevNum ($num) {
		if ($num <= 0) return 9;
		return $num - 1;
	}
	
	# Get the next number for keyboards
	private function nextNum ($num) {
		if ($num >= 9) return 0;
		return $num + 1;
	}
	
	# Sort dwellers data
	public function sortDwellers ($dwellers = [], $by = "serializeId") {
		if (empty($dwellers)) return $dwellers;
		$newdwellers = [];
		foreach($dwellers as $dweller) {
			$newdwellers[$dweller[$by]] = $dweller;
		}
		sort($newdwellers);
		return array_reverse($newdwellers, true);
	}
	
	# Load the game translations on Redis for more speed (Redis only)
	private function redisCheck () {
		if (!$this->db->rget('tr-' . $this->game_name . '-status')) {
			$this->db->rset('tr-' . $this->game_name . '-status', true, $this->cache_time);
			$trs = $this->getAllTranslations();
			if ($trs['ok']) {
				$this->db->rdel($this->db->rkeys('tr-' . $this->game_name . '*'));
				$this->db->rset('tr-' . $this->game_name . '-status', true, $this->cache_time);
				foreach ($trs['result'] as $lang => $strings) {
					$lang = explode('-', $lang, 2)[0];
					foreach($strings as $stringn => $translation) {
						$this->db->rset('tr-' . $this->game_name . '-' . $lang . '-' . $stringn, $translation, $this->cache_time);
					}
				}
				return true;
			} else {
				$this->db->rdel('tr-' . $this->game_name . '-status');
			}
		}
		return false;
	}
	
	# Reload game translations
	public function reload () {
		if (isset($this->db->configs) and $this->db->configs['redis']['status']) {
			$this->db->rdel('tr-' . $this->game_name . '-status');
			return $this->redisCheck();
		}
		return false;
	}
	
	# Get game translation from string ID
	public function getTranslation($string, $args = [], $user_lang = 'def') {
		if ($user_lang == 'def') {
			$lang = $this->lang;
		} elseif (in_array(strtolower($user_lang), $this->langs)) {
			$lang = strtolower($user_lang);
		} else {
			$lang = 'en';
		}
		$string = str_replace(' ', '', $string);
		if (isset($this->db->configs)) {
			if ($lang !== 'en' and $t_string = $this->db->rget('tr-' . $this->game_name . '-' . $lang . '-' . $string)) {
			} elseif ($t_string = $this->db->rget('tr-' . $this->game_name . '-en-' . $string)) {
			} else {
				$t_string = '👾';
			}
		} else {
			if ($lang !== 'en' and $t_string = $this->translations[$lang][$string]) {
				
			} elseif ($t_string = $this->translations['en'][$string]) {
				
			} else {
				$t_string = '🤖';
			}
		}
		if (!empty($args) and is_array($args)) {
			$args = array_values($args);
			foreach(range(0, count($args) - 1) as $num) {
				$t_string = str_replace('[' . $num . ']', $args[$num], $t_string);
			}
		}
		return $t_string;
	}
	
	# Get all translations from the file or from the current script
	public function getAllTranslations () {
		if (isset($this->translations)) {
			return ['ok' => 1, 'result' => $this->translations];
		} elseif (file_exists($this->translations_file)) {
			$file = file_get_contents($this->translations_file);
			if ($file) {
				if ($translations = json_decode($file, 1)) {
					return ['ok' => 1, 'result' => $translations];
				}
				return ['ok' => 1, 'result' => [], 'notice' => 'Failed to get JSON format from the file!'];
			}
			return ['ok' => 0, 'result' => [], 'notice' => 'The file is empty!'];
		} else {
			return ['ok' => 1, 'result' => [], 'notice' => 'No configs for translations'];
		}
	}
}

?>