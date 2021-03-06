<?php declare(strict_types=1); defined('BASEPATH') OR exit('No direct script access allowed');

class Tracker_Sites_Model extends CI_Model {
	public function __construct() {
		parent::__construct();
	}

	public function __get($name) {
		//TODO: Is this a good idea? There wasn't a good consensus on if this is good practice or not..
		//      It's probably a minor speed reduction, but that isn't much of an issue.
		//      An alternate solution would simply have a function which generates a PHP file with code to load each model. Similar to: https://github.com/shish/shimmie2/blob/834bc740a4eeef751f546979e6400fd089db64f8/core/util.inc.php#L1422
		if(!class_exists($name) || !(in_array(get_parent_class($name), ['Base_Site_Model', 'Base_FoolSlide_Site_Model']))) {
			return get_instance()->{$name};
		} else {
			$this->loadSite($name);
			return $this->{$name};
		}
	}

	private function loadSite(string $siteName) {
		$this->{$siteName} = new $siteName();
	}
}

abstract class Base_Site_Model extends CI_Model {
	public $site          = '';
	public $titleFormat   = '';
	public $chapterFormat = '';

	/**
	 * 0: No custom updater.
	 * 1: Uses following page.
	 * 2: Uses latest releases page.
	 */
	public $customType = 0;

	public function __construct() {
		parent::__construct();

		$this->load->database();

		$this->site = get_class($this);
	}

	abstract public function getFullTitleURL(string $title_url) : string;

	abstract public function getChapterData(string $title_url, string $chapter) : array;

	abstract public function getTitleData(string $title_url, bool $firstGet = FALSE) : ?array;

	final public function isValidTitleURL(string $title_url) : bool {
		$success = (bool) preg_match($this->titleFormat, $title_url);
		if(!$success) log_message('error', "Invalid Title URL ({$this->site}): {$title_url}");
		return $success;
	}
	final public function isValidChapter(string $chapter) : bool {
		$success = (bool) preg_match($this->chapterFormat, $chapter);
		if(!$success) log_message('error', "Invalid Chapter ({$this->site}): {$chapter}");
		return $success;
	}

	final protected function get_content(string $url, string $cookie_string = "", string $cookiejar_path = "", bool $follow_redirect = FALSE, bool $isPost = FALSE, array $postFields = []) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_ENCODING , "gzip");
		//curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);

		if($follow_redirect)        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

		if(!empty($cookie_string))  curl_setopt($ch, CURLOPT_COOKIE, $cookie_string);
		if(!empty($cookiejar_path)) curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiejar_path);

		//Some sites check the useragent for stuff, use a pre-defined user-agent to avoid stuff.
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2824.0 Safari/537.36');

		//TODO: Check in a while if this being enabled still causes issues
		//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); //FIXME: This isn't safe, but it allows us to grab SSL URLs

		curl_setopt($ch, CURLOPT_URL, $url);

		if($isPost) {
			curl_setopt($ch,CURLOPT_POST, count($postFields));
			curl_setopt($ch,CURLOPT_POSTFIELDS, http_build_query($postFields));
		}

		$response = curl_exec($ch);
		if($response === FALSE) {
			log_message('error', "curl failed with error: ".curl_errno($ch)." | ".curl_error($ch));
			//FIXME: We don't always account for FALSE return
			return FALSE;
		}

		$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headers     = http_parse_headers(substr($response, 0, $header_size));
		$body        = substr($response, $header_size);
		curl_close($ch);

		return [
			'headers'     => $headers,
			'status_code' => $status_code,
			'body'        => $body
		];
	}

	/**
	 * @param array  $content
	 * @param string $title_url
	 * @param string $node_title_string
	 * @param string $node_row_string
	 * @param string $node_latest_string
	 * @param string $node_chapter_string
	 * @param string $failure_string
	 *
	 * @return DOMElement[]|false
	 */
	final protected function parseTitleDataDOM(
		$content, string $title_url,
		string $node_title_string, string $node_row_string,
		string $node_latest_string, string $node_chapter_string,
		string $failure_string = "") {

		if(!is_array($content)) {
			log_message('error', "{$this->site} : {$title_url} | Failed to grab URL (See above curl error)");
		} else {
			list('headers' => $headers, 'status_code' => $status_code, 'body' => $data) = $content;

			if(!($status_code >= 200 && $status_code < 300)) {
				log_message('error', "{$this->site} : {$title_url} | Bad Status Code ({$status_code})");
			} else if(empty($data)) {
				log_message('error', "{$this->site} : {$title_url} | Data is empty? (Status code: {$status_code})");
			} else if($failure_string !== "" && strpos($data, $failure_string) !== FALSE) {
				log_message('error', "{$this->site} : {$title_url} | Failure string matched");
			} else {
				$data = $this->cleanTitleDataDOM($data); //This allows us to clean the DOM prior to parsing. It's faster to grab the only part we need THEN parse it.

				$dom = new DOMDocument();
				libxml_use_internal_errors(TRUE);
				$dom->loadHTML('<?xml encoding="utf-8" ?>' . $data);
				libxml_use_internal_errors(FALSE);

				$xpath = new DOMXPath($dom);
				$nodes_title = $xpath->query($node_title_string);
				$nodes_row   = $xpath->query($node_row_string);
				if($nodes_title->length === 1 && $nodes_row->length === 1) {
					$firstRow      = $nodes_row->item(0);
					$nodes_latest  = $xpath->query($node_latest_string,  $firstRow);

					if($node_chapter_string !== '') {
						$nodes_chapter = $xpath->query($node_chapter_string, $firstRow);
					} else {
						$nodes_chapter = $nodes_row;
					}

					if($nodes_latest->length === 1 && $nodes_chapter->length === 1) {
						return [
							'nodes_title'   => $nodes_title->item(0),
							'nodes_latest'  => $nodes_latest->item(0),
							'nodes_chapter' => $nodes_chapter->item(0)
						];
					} else {
						log_message('error', "{$this->site} : {$title_url} | Invalid amount of nodes (LATEST: {$nodes_latest->length} | CHAPTER: {$nodes_chapter->length})");
					}
				} else {
					log_message('error', "{$this->site} : {$title_url} | Invalid amount of nodes (TITLE: {$nodes_title->length} | ROW: {$nodes_row->length})");
				}
			}
		}

		return FALSE;
	}

	public function cleanTitleDataDOM(string $data) : string {
		return $data;
	}

	final public function doCustomFollow(string $data = "", array $extra = []) : array {
		$titleData = [];
		$this->handleCustomFollow(function($content, $id, closure $successCallback = NULL) use(&$titleData) {
			if(is_array($content)) {
				if(array_key_exists('status_code', $content)) {
					$statusCode = $content['status_code'];
					if($statusCode === 200) {
						$isCallable = is_callable($successCallback);
						if(($isCallable && $successCallback($content['body'])) || !$isCallable) {
							$titleData['followed'] = 'Y';

							log_message('info', "doCustomFollow succeeded for {$id}");
						} else {
							log_message('error', "doCustomFollow failed (Invalid response?) for {$id}");
						}
					} else {
						log_message('error', "doCustomFollow failed (Invalid status code ({$statusCode})) for {$id}");
					}
				} else {
					log_message('error', "doCustomFollow failed (Missing status code?) for {$id}");
				}
			} else {
				log_message('error', "doCustomFollow failed (Failed request) for {$id}");
			}
		}, $data, $extra);
		return $titleData;
	}
	public function handleCustomFollow(callable $callback, string $data = "", array $extra = []) {}
	public function doCustomUpdate() {}
	public function doCustomCheck(string $oldChapter, string $newChapter) {}
	final public function doCustomCheckCompare(array $oldChapterSegments, array $newChapterSegments) : bool {
		//FIXME: Make this more generic when we have more site support for it. MangaFox and Batoto have similar chapter formats.

		//NOTE: We only need to check against the new chapter here, as that is what is used for confirming update.
		$status = FALSE;

		//Make sure we have a volume element
		if(count($oldChapterSegments) === 1) array_unshift($oldChapterSegments, 'v0');
		if(count($newChapterSegments) === 1) array_unshift($newChapterSegments, 'v0');

		$oldCount = count($oldChapterSegments);
		$newCount = count($newChapterSegments);
		if($newCount === $oldCount) {
			//Make sure chapter format looks correct.
			//NOTE: We only need to check newCount as we know oldCount is the same count.
			if($newCount === 2) {
				//FIXME: Can we loop this?
				$oldVolume = substr(array_shift($oldChapterSegments), 1);
				$newVolume = substr(array_shift($newChapterSegments), 1);

				//Forcing volume to 0 as TBD might not be the latest (although it can be, but that is covered by other checks)
				if(in_array($oldVolume, ['TBD', 'TBA', 'NA', 'LMT'])) $oldVolume = 0;
				if(in_array($newVolume, ['TBD', 'TBA', 'NA', 'LMT'])) $newVolume = 0;

				$oldVolume = floatval($oldVolume);
				$newVolume = floatval($newVolume);
			} else {
				$oldVolume = 0;
				$newVolume = 0;
			}
			$oldChapter = floatval(substr(array_shift($oldChapterSegments), 1));
			$newChapter = floatval(substr(array_shift($newChapterSegments), 1));

			if($newChapter > $oldChapter && ($oldChapter >= 10 && $newChapter >= 10)) {
				//$newChapter is higher than $oldChapter AND $oldChapter and $newChapter are both more than 10
				//This is intended to cover the /majority/ of valid updates, as we technically shouldn't have to check volumes.

				$status = TRUE;
			} elseif($newVolume > $oldVolume && ($oldChapter < 10 && $newChapter < 10)) {
				//This is pretty much just to match a one-off case where the site doesn't properly increment chapter numbers across volumes, and instead does something like: v1/c1..v1/c5, v2/c1..v1/c5 (and so on).
				$status = TRUE;
			} elseif($newVolume > $oldVolume && $newChapter >= $oldChapter) {
				//$newVolume is higher, and chapter is higher so no need to check chapter.
				$status = TRUE;
			} elseif($newChapter > $oldChapter) {
				//$newVolume isn't higher, but chapter is.
				$status = TRUE;
			}
		}

		return $status;
	}
}

abstract class Base_FoolSlide_Site_Model extends Base_Site_Model {
	public $titleFormat   = '/^[a-z0-9_-]+$/';
	public $chapterFormat = '/^en(?:-us)?\/[0-9]+(?:\/[0-9]+(?:\/[0-9]+(?:\/[0-9]+)?)?)?$/';
	public $customType    = 2;

	public $baseURL = '';

	public function getFullTitleURL(string $title_url) : string {
		return "{$this->baseURL}/series/{$title_url}";
	}

	public function getChapterData(string $title_url, string $chapter) : array {
		$chapter_parts = explode('/', $chapter); //returns #LANG#/#VOLUME#/#CHAPTER#/#CHAPTER_EXTRA#(/#PAGE#/)
		return [
			'url'    => "{$this->baseURL}/read/{$title_url}/{$chapter}/",
			'number' => ($chapter_parts[1] !== '0' ? "v{$chapter_parts[1]}/" : '') . "c{$chapter_parts[2]}" . (isset($chapter_parts[3]) ? ".{$chapter_parts[3]}" : '')/*)*/
		];
	}

	public function getTitleData(string $title_url, bool $firstGet = FALSE) : ?array {
		$titleData = [];

		$jsonURL = "{$this->baseURL}/api/reader/comic/stub/{$title_url}/format/json";
		if($content = $this->get_content($jsonURL)) {
			$json = json_decode($content['body'], TRUE);
			if($json && isset($json['chapters']) && count($json['chapters']) > 0) {
				$titleData['title'] = trim($json['comic']['name']);

				//FoolSlide title API doesn't appear to let you sort (yet every other API method which has chapters does, so we need to sort ourselves..
				usort($json['chapters'], function($a, $b) {
					return floatval("{$b['chapter']['chapter']}.{$b['chapter']['subchapter']}") <=> floatval("{$a['chapter']['chapter']}.{$a['chapter']['subchapter']}");
				});
				$latestChapter = reset($json['chapters'])['chapter'];

				$latestChapterString = "{$latestChapter['language']}/{$latestChapter['volume']}/{$latestChapter['chapter']}";
				if($latestChapter['subchapter'] !== '0') {
					$latestChapterString .= "/{$latestChapter['subchapter']}";
				}
				$titleData['latest_chapter'] = $latestChapterString;

				//No need to use date() here since this is already formatted as such.
				$titleData['last_updated'] = ($latestChapter['updated'] !== '0000-00-00 00:00:00' ? $latestChapter['updated'] : $latestChapter['created']);
			}
		}

		return (!empty($titleData) ? $titleData : NULL);
	}

	//Since we're just checking the latest updates page and not a following page, we just need to simulate a follow.
	//TODO: It would probably be better to have some kind of var which says that the custom update uses a following page..
	public function handleCustomFollow(callable $callback, string $data = "", array $extra = []) {
		$content = ['status_code' => 200];
		$callback($content, $extra['id']);
	}
	public function doCustomUpdate() {
		$titleDataList = [];

		$jsonURL = "{$this->baseURL}/api/reader/chapters/orderby/desc_created/format/json";
		if(($content = $this->get_content($jsonURL)) && $content['status_code'] == 200) {
			$json = json_decode($content['body'], TRUE);

			//This should fix edge cases where chapters are uploaded in bulk in the wrong order (HelveticaScans does this with Mousou Telepathy).
			usort($json['chapters'], function($a, $b) {
				$a_date = new DateTime($a['chapter']['updated'] !== '0000-00-00 00:00:00' ? $a['chapter']['updated'] : $a['chapter']['created']);
				$b_date = new DateTime($b['chapter']['updated'] !== '0000-00-00 00:00:00' ? $b['chapter']['updated'] : $b['chapter']['created']);
				return $b_date <=> $a_date;
			});

			$parsedTitles = [];
			foreach($json['chapters'] as $chapterData) {
				if(!in_array($chapterData['comic']['stub'], $parsedTitles)) {
					$parsedTitles[] = $chapterData['comic']['stub'];

					$titleData = [];
					$titleData['title'] = trim($chapterData['comic']['name']);

					$latestChapter = $chapterData['chapter'];

					$latestChapterString = "en/{$latestChapter['volume']}/{$latestChapter['chapter']}";
					if($latestChapter['subchapter'] !== '0') {
						$latestChapterString .= "/{$latestChapter['subchapter']}";
					}
					$titleData['latest_chapter'] = $latestChapterString;

					//No need to use date() here since this is already formatted as such.
					$titleData['last_updated'] = ($latestChapter['updated'] !== '0000-00-00 00:00:00' ? $latestChapter['updated'] : $latestChapter['created']);

					$titleDataList[$chapterData['comic']['stub']] = $titleData;
				} else {
					//We already have title data for this title.
					continue;
				}
			}
		} else {
			log_message('error', "Custom updating failed for {$this->baseURL}.");
		}

		return $titleDataList;
	}
	public function doCustomCheck(string $oldChapterString, string $newChapterString) {
		$oldChapterSegments = explode('/', $this->getChapterData('', $oldChapterString)['number']);
		$newChapterSegments = explode('/', $this->getChapterData('', $newChapterString)['number']);

		$status = $this->doCustomCheckCompare($oldChapterSegments, $newChapterSegments);

		return $status;
	}
}
