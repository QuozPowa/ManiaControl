<?php
use ManiaControl\Database;
use ManiaControl\Formatter;
use ManiaControl\ManiaControl;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Maps\Map;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use FML\ManiaLink;
use FML\Controls\Control;
use FML\Controls\Frame;
use FML\Controls\Label;
use FML\Controls\Quad;
use FML\Controls\Labels\Label_Text;

/**
 * ManiaControl Local Records Plugin
 *
 * @author steeffeen
 */
class LocalRecordsPlugin extends Plugin implements CallbackListener {
	/**
	 * Constants
	 */
	const VERSION = '1.0';
	const MLID_RECORDS = 'ml_local_records';
	const TABLE_RECORDS = 'mc_localrecords';
	
	/**
	 * Private properties
	 */
	private $updateManialink = false;

	/**
	 * Create new local records plugin
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		
		// Init tables
		$this->initTables();
		
		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_ONINIT, $this, 'handleOnInit');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_1_SECOND, $this, 'handle1Second');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_BEGINMAP, $this, 'handleMapBegin');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_CLIENTUPDATED, $this, 
				'handleClientUpdated');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_TM_PLAYERFINISH, $this, 
				'handlePlayerFinish');
	}

	/**
	 * Initialize needed database tables
	 */
	private function initTables() {
		$mysqli = $this->maniaControl->database->mysqli;
		$query = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_RECORDS . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`mapIndex` int(11) NOT NULL,
				`playerIndex` int(11) NOT NULL,
				`time` int(11) NOT NULL,
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `player_map_record` (`mapIndex`,`playerIndex`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1;";
		if (!$mysqli->query($query)) {
			trigger_error("Couldn't create records table. " . $mysqli->error, E_USER_ERROR);
		}
	}

	/**
	 * Handle ManiaControl init
	 *
	 * @param array $callback        	
	 */
	public function handleOnInit(array $callback) {
		// Let manialinks update
		$this->updateManialink = true;
	}

	/**
	 * Handle 1Second callback
	 *
	 * @param array $callback        	
	 */
	public function handle1Second(array $callback) {
		// Send records manialinks if needed
		if ($this->updateManialink) {
			$manialink = $this->buildLocalManialink();
			$this->sendManialink($manialink);
			$this->updateManialink = false;
		}
	}

	/**
	 * Handle PlayerConnect callback
	 *
	 * @param array $callback        	
	 */
	public function handlePlayerConnect(array $callback) {
		$this->updateManialink = true;
	}

	/**
	 * Handle BeginMap callback
	 *
	 * @param array $callback        	
	 */
	public function handleMapBegin(array $callback) {
		$this->updateManialink = true;
	}

	/**
	 * Handle PlayerFinish callback
	 *
	 * @param array $callback        	
	 */
	public function handlePlayerFinish(array $callback) {
		$data = $callback[1];
		if ($data[0] <= 0 || $data[2] <= 0) {
			// Invalid player or time
			return;
		}
		
		$login = $data[1];
		$player = $this->maniaControl->playerManager->getPlayer($login);
		if (!$player) {
			// Invalid player
			return;
		}
		
		$time = $data[2];
		$map = $this->maniaControl->mapManager->getCurrentMap();
		
		// Check old record of the player
		$oldRecord = $this->getLocalRecord($map, $player);
		if ($oldRecord) {
			if ($oldRecord->time < $time) {
				// Not improved
				return;
			}
			if ($oldRecord->time == $time) {
				// Same time
				$message = '$<' . $player->nickname . '$> equalized her/his $<$o' . $oldRecord->rank . '.$> Local Record: ' .
						 Formatter::formatTime($oldRecord->time);
				$this->maniaControl->chat->sendInformation($message);
				return;
			}
		}
		
		// Save time
		$mysqli = $this->maniaControl->database->mysqli;
		$query = "INSERT INTO `" . self::TABLE_RECORDS . "` (
				`mapIndex`,
				`playerIndex`,
				`time`
				) VALUES (
				{$map->index},
				{$player->index},
				{$time}
				) ON DUPLICATE KEY UPDATE
				`time` = VALUES(`time`);";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}
		$this->updateManialink = true;
		
		// Announce record
		$newRecord = $this->getLocalRecord($map, $player);
		if (!$oldRecord || $newRecord->rank < $oldRecord->rank) {
			$improvement = 'gained the';
		}
		else {
			$improvement = 'improved her/his';
		}
		$message = '$<' . $player->nickname . '$> ' . $improvement . ' $<$o' . $newRecord->rank . '.$> Local Record: ' .
				 Formatter::formatTime($newRecord->time);
		$this->maniaControl->chat->sendInformation($message);
	}

	/**
	 * Send manialink to clients
	 *
	 * @param string $manialink        	
	 * @param string $login        	
	 */
	private function sendManialink($manialink, $login = null) {
		if ($login) {
			if (!$this->maniaControl->client->query('SendDisplayManialinkPageToLogin', $login, $manialink, 0, false)) {
				trigger_error("Couldn't send manialink to player '{$login}'. " . $this->maniaControl->getClientErrorText());
			}
			return;
		}
		if (!$this->maniaControl->client->query('SendDisplayManialinkPage', $manialink, 0, false)) {
			trigger_error("Couldn't send manialink to players. " . $this->maniaControl->getClientErrorText());
		}
	}

	/**
	 * Handle ClientUpdated callback
	 *
	 * @param array $data        	
	 */
	public function handleClientUpdated(array $callback) {
		$this->updateManialink = true;
	}

	/**
	 * Build the local records manialink
	 *
	 * @return string
	 */
	private function buildLocalManialink() {
		$map = $this->maniaControl->mapManager->getCurrentMap();
		if (!$map) {
			return null;
		}
		
		$pos_x = $this->maniaControl->settingManager->getSetting($this, 'Widget_PosX', -139.);
		$pos_y = $this->maniaControl->settingManager->getSetting($this, 'Widget_PosY', 65.);
		$title = $this->maniaControl->settingManager->getSetting($this, 'Widget_Title', 'Local Records');
		$width = $this->maniaControl->settingManager->getSetting($this, 'Widget_Width', 40.);
		$lines = $this->maniaControl->settingManager->getSetting($this, 'Widget_LinesCount', 25);
		$line_height = $this->maniaControl->settingManager->getSetting($this, 'Widget_LineHeight', 4.);
		
		$records = $this->getLocalRecords($map);
		if (!is_array($records)) {
			trigger_error("Couldn't fetch player records.");
			return null;
		}
		
		$manialink = new ManiaLink(self::MLID_RECORDS);
		$frame = new Frame();
		$manialink->add($frame);
		$frame->setPosition($pos_x, $pos_y);
		
		$backgroundQuad = new Quad();
		$frame->add($backgroundQuad);
		$backgroundQuad->setVAlign(Control::TOP);
		$backgroundQuad->setSize($width * 1.05, 7. + $lines * $line_height);
		$backgroundQuad->setStyles('Bgs1InRace', 'BgTitleShadow');
		
		$titleLabel = new Label();
		$frame->add($titleLabel);
		// TODO: set translateable
		$titleLabel->setPosition(0, $line_height * -0.9);
		$titleLabel->setSize($width);
		$titleLabel->setStyle(Label_Text::STYLE_TextTitle1);
		$titleLabel->setTextSize(2);
		$titleLabel->setText($title);
		
		// Times
		foreach ($records as $index => $record) {
			$y = -8. - $index * $line_height;
			
			$recordFrame = new Frame();
			$frame->add($recordFrame);
			$recordFrame->setPosition(0, $y);
			
			$backgroundQuad = new Quad();
			$recordFrame->add($backgroundQuad);
			$backgroundQuad->setSize($width, $line_height);
			$backgroundQuad->setStyles('Bgs1InRace', 'BgTitleGlow');
			
			$rankLabel = new Label();
			$recordFrame->add($rankLabel);
			$rankLabel->setHAlign(Control::LEFT);
			$rankLabel->setPosition($width * -0.47);
			$rankLabel->setSize($width * 0.06, $line_height);
			$rankLabel->setTextSize(1);
			$rankLabel->setTextPrefix('$o');
			$rankLabel->setText($record->rank);
			
			$nameLabel = new Label();
			$recordFrame->add($nameLabel);
			$nameLabel->setHAlign(Control::LEFT);
			$nameLabel->setPosition($width * -0.4);
			$nameLabel->setSize($width * 0.6, $line_height);
			$nameLabel->setTextSize(1);
			$nameLabel->setText($record->nickname);
			
			$timeLabel = new Label();
			$recordFrame->add($timeLabel);
			$timeLabel->setHAlign(Control::RIGHT);
			$timeLabel->setPosition($width * 0.47);
			$timeLabel->setSize($width * 0.25, $line_height);
			$timeLabel->setTextSize(1);
			$timeLabel->setText(Formatter::formatTime($record->time));
		}
		
		return $manialink->render()->saveXML();
	}

	/**
	 * Fetch local records for the given map
	 *
	 * @param Map $map        	
	 * @param int $limit        	
	 * @return array
	 */
	private function getLocalRecords(Map $map, $limit = -1) {
		$mysqli = $this->maniaControl->database->mysqli;
		$limit = ($limit > 0 ? "LIMIT " . $limit : "");
		$query = "SELECT * FROM (
					SELECT recs.*, @rank := @rank + 1 as `rank` FROM `" . self::TABLE_RECORDS . "` recs, (SELECT @rank := 0) ra
					WHERE recs.`mapIndex` = {$map->index}
					ORDER BY recs.`time` ASC
					{$limit}) records
				LEFT JOIN `" . PlayerManager::TABLE_PLAYERS . "` players
				ON records.`playerIndex` = players.`index`;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		$records = array();
		while ($record = $result->fetch_object()) {
			array_push($records, $record);
		}
		$result->free();
		return $records;
	}

	/**
	 * Retrieve the local record for the given map and login
	 *
	 * @param Map $map        	
	 * @param Player $player        	
	 * @return mixed
	 */
	private function getLocalRecord(Map $map, Player $player) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query = "SELECT records.* FROM (
					SELECT recs.*, @rank := @rank + 1 as `rank` FROM `" . self::TABLE_RECORDS . "` recs, (SELECT @rank := 0) ra
					WHERE recs.`mapIndex` = {$map->index}
					ORDER BY recs.`time` ASC) records
				WHERE records.`playerIndex` = {$player->index};";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error("Couldn't retrieve player record for '{$player->login}'." . $mysqli->error);
			return null;
		}
		$record = $result->fetch_object();
		$result->free();
		return $record;
	}
}

?>
