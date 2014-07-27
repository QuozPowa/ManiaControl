<?php

namespace ManiaControl\Configurator;

use FML\Controls\Frame;
use FML\Controls\Labels\Label_Text;
use FML\Controls\Quad;
use FML\Controls\Quads\Quad_BgRaceScore2;
use FML\Controls\Quads\Quad_Icons64x64_1;
use FML\Controls\Quads\Quad_UIConstruction_Buttons;
use FML\ManiaLink;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;
use ManiaControl\Players\Player;
use ManiaControl\Server\ServerOptionsMenu;
use ManiaControl\Server\VoteRatiosMenu;

/**
 * Class managing ingame ManiaControl Configuration
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class Configurator implements CallbackListener, CommandListener, ManialinkPageAnswerListener {
	/*
	 * Constants
	 */
	const ACTION_TOGGLEMENU                    = 'Configurator.ToggleMenuAction';
	const ACTION_SAVECONFIG                    = 'Configurator.SaveConfigAction';
	const ACTION_SELECTMENU                    = 'Configurator.SelectMenu.';
	const SETTING_MENU_POSX                    = 'Menu Widget Position: X';
	const SETTING_MENU_POSY                    = 'Menu Widget Position: Y';
	const SETTING_MENU_WIDTH                   = 'Menu Widget Width';
	const SETTING_MENU_HEIGHT                  = 'Menu Widget Height';
	const SETTING_MENU_STYLE                   = 'Menu Widget BackgroundQuad Style';
	const SETTING_MENU_SUBSTYLE                = 'Menu Widget BackgroundQuad Substyle';
	const SETTING_PERMISSION_OPEN_CONFIGURATOR = 'Open Configurator';
	const CACHE_MENU_SHOWN                     = 'MenuShown';
	const MENU_NAME                            = 'Configurator';

	/*
	 * Private properties
	 */
	private $maniaControl = null;
	/** @var ServerOptionsMenu $serverOptionsMenu */
	private $serverOptionsMenu = null;
	/** @var ScriptSettings $scriptSettings */
	private $scriptSettings = null;
	/** @var VoteRatiosMenu $voteRatiosMenu */
	private $voteRatiosMenu = null;
	/** @var ManiaControlSettings $maniaControlSettings */
	private $maniaControlSettings = null;
	/** @var ConfiguratorMenu[] $menus */
	private $menus = array();

	/**
	 * Create a new configurator instance
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->addActionsMenuItem();

		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_POSX, 0.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_POSY, 3.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_WIDTH, 170.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_HEIGHT, 81.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_STYLE, Quad_BgRaceScore2::STYLE);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MENU_SUBSTYLE, Quad_BgRaceScore2::SUBSTYLE_HandleSelectable);

		// Permission for opening
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_OPEN_CONFIGURATOR, AuthenticationManager::AUTH_LEVEL_ADMIN);

		// Register for page answers
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_TOGGLEMENU, $this, 'handleToggleMenuAction');
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_SAVECONFIG, $this, 'handleSaveConfigAction');

		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');
		$this->maniaControl->callbackManager->registerCallbackListener(ManialinkManager::CB_MAIN_WINDOW_OPENED, $this, 'handleWidgetOpened');
		$this->maniaControl->callbackManager->registerCallbackListener(ManialinkManager::CB_MAIN_WINDOW_CLOSED, $this, 'closeWidget');

		// Create server options menu
		$this->serverOptionsMenu = new ServerOptionsMenu($maniaControl);
		$this->addMenu($this->serverOptionsMenu);

		// Create script settings
		$this->scriptSettings = new ScriptSettings($maniaControl);
		$this->addMenu($this->scriptSettings);

		// Create vote ratios menu
		$this->voteRatiosMenu = new VoteRatiosMenu($maniaControl);
		$this->addMenu($this->voteRatiosMenu);

		// Create Mania Control Settings
		$this->maniaControlSettings = new ManiaControlSettings($maniaControl);
		$this->addMenu($this->maniaControlSettings);

		// Register for commands
		$this->maniaControl->commandManager->registerCommandListener('config', $this, 'handleConfigCommand', true, 'Loads Config panel.');
	}

	/**
	 * Add Menu Item to the Actions Menu
	 */
	private function addActionsMenuItem() {
		$itemQuad = new Quad_UIConstruction_Buttons();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_Tools)
		         ->setAction(self::ACTION_TOGGLEMENU);
		$this->maniaControl->actionsMenu->addAdminMenuItem($itemQuad, 100, 'Settings');
	}

	/**
	 * Add a configurator menu
	 *
	 * @param ConfiguratorMenu $menu
	 */
	public function addMenu(ConfiguratorMenu $menu) {
		array_push($this->menus, $menu);
	}

	/**
	 * Handle Config Admin Command
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handleConfigCommand(array $callback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_OPEN_CONFIGURATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}

		$this->showMenu($player);
	}

	/**
	 * Show the Menu to the Player
	 *
	 * @param Player $player
	 * @param mixed  $menuId
	 */
	public function showMenu(Player $player, $menuId = 0) {
		if ($menuId instanceof ConfiguratorMenu) {
			$menuId = $this->getMenuId($menuId->getTitle());
		}
		$manialink = $this->buildManialink($menuId, $player);
		$this->maniaControl->manialinkManager->displayWidget($manialink, $player, self::MENU_NAME);
		$player->setCache($this, self::CACHE_MENU_SHOWN, true);
	}

	/**
	 * Gets the Menu Id
	 *
	 * @param string $title
	 * @return int
	 */
	public function getMenuId($title) {
		$index = 0;
		foreach ($this->menus as $menu) {
			if ($menu === $title || $menu->getTitle() === $title) {
				return $index;
			}
			$index++;
		}
		return 0;
	}

	/**
	 * Build Menu ManiaLink if necessary
	 *
	 * @param int    $menuIdShown
	 * @param Player $player
	 * @return \FML\ManiaLink
	 */
	private function buildManialink($menuIdShown = 0, Player $player = null) {
		$menuPosX     = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_POSX);
		$menuPosY     = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_POSY);
		$menuWidth    = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_WIDTH);
		$menuHeight   = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_HEIGHT);
		$quadStyle    = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_STYLE);
		$quadSubstyle = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MENU_SUBSTYLE);

		$menuListWidth  = $menuWidth * 0.3;
		$menuItemHeight = 10.;
		$subMenuWidth   = $menuWidth - $menuListWidth;
		$subMenuHeight  = $menuHeight;

		$manialink = new ManiaLink(ManialinkManager::MAIN_MLID);

		$frame = new Frame();
		$manialink->add($frame);
		$frame->setPosition($menuPosX, $menuPosY, 10);

		$backgroundQuad = new Quad();
		$frame->add($backgroundQuad);
		$backgroundQuad->setZ(-10)
		               ->setSize($menuWidth, $menuHeight)
		               ->setStyles($quadStyle, $quadSubstyle);

		$menuItemsFrame = new Frame();
		$frame->add($menuItemsFrame);
		$menuItemsFrame->setX($menuWidth * -0.5 + $menuListWidth * 0.5);

		$itemsBackgroundQuad = new Quad();
		$menuItemsFrame->add($itemsBackgroundQuad);
		$backgroundQuad->setZ(-9);
		$itemsBackgroundQuad->setSize($menuListWidth, $menuHeight)
		                    ->setStyles($quadStyle, $quadSubstyle);

		$menusFrame = new Frame();
		$frame->add($menusFrame);
		$menusFrame->setX($menuWidth * -0.5 + $menuListWidth + $subMenuWidth * 0.5);

		// Create script and features
		$script = $manialink->getScript();

		$menuItemY = $menuHeight * 0.42;
		$menuId    = 0;
		foreach ($this->menus as $menu) {
			// Add title
			$menuItemLabel = new Label_Text();
			$menuItemsFrame->add($menuItemLabel);
			$menuItemLabel->setY($menuItemY)
			              ->setSize($menuListWidth * 0.9, $menuItemHeight * 0.9)
			              ->setStyle($menuItemLabel::STYLE_TextCardRaceRank)
			              ->setText($menu->getTitle())
			              ->setAction(self::ACTION_SELECTMENU . $menuId);

			// Show the menu
			if ($menuId === $menuIdShown) {
				$menuControl = $menu->getMenu($subMenuWidth, $subMenuHeight, $script, $player);
				if ($menuControl) {
					$menusFrame->add($menuControl);
				} else {
					$this->maniaControl->chat->sendError('Error loading Menu!', $player);
				}
			}

			$menuItemY -= $menuItemHeight * 1.1;
			$menuId++;
		}

		// Add Close Quad (X)
		$closeQuad = new Quad_Icons64x64_1();
		$frame->add($closeQuad);
		$closeQuad->setPosition($menuWidth * 0.483, $menuHeight * 0.467, 3)
		          ->setSize(6, 6)
		          ->setSubStyle($closeQuad::SUBSTYLE_QuitRace)
		          ->setAction(ManialinkManager::ACTION_CLOSEWIDGET);

		// Add close button
		$closeButton = new Label_Text();
		$frame->add($closeButton);
		$closeButton->setPosition($menuWidth * -0.5 + $menuListWidth * 0.29, $menuHeight * -0.43)
		            ->setSize($menuListWidth * 0.3, $menuListWidth * 0.1)
		            ->setStyle($closeButton::STYLE_TextButtonNavBack)
		            ->setTextPrefix('$999')
		            ->setTranslate(true)
		            ->setText('Close')
		            ->setAction(self::ACTION_TOGGLEMENU);

		// Add save button
		$saveButton = new Label_Text();
		$frame->add($saveButton);
		$saveButton->setPosition($menuWidth * -0.5 + $menuListWidth * 0.71, $menuHeight * -0.43)
		           ->setSize($menuListWidth * 0.3, $menuListWidth * 0.1)
		           ->setStyle($saveButton::STYLE_TextButtonNavBack)
		           ->setTextPrefix('$0f5')
		           ->setTranslate(true)
		           ->setText('Save')
		           ->setAction(self::ACTION_SAVECONFIG);

		return $manialink;
	}

	/**
	 * Handle toggle menu action
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handleToggleMenuAction(array $callback, Player $player) {
		$this->toggleMenu($player);
	}

	/**
	 * Toggle the Menu for the Player
	 *
	 * @param Player $player
	 */
	public function toggleMenu(Player $player) {
		if ($player->getCache($this, self::CACHE_MENU_SHOWN)) {
			$this->hideMenu($player);
		} else {
			$this->showMenu($player);
		}
	}

	/**
	 * Hide the Menu for the Player
	 *
	 * @param Player $player
	 */
	public function hideMenu(Player $player) {
		$this->closeWidget($player);
		$this->maniaControl->manialinkManager->closeWidget($player);
	}

	/**
	 * Handle widget being closed
	 *
	 * @param Player $player
	 */
	public function closeWidget(Player $player) {
		$player->destroyCache($this, self::CACHE_MENU_SHOWN);
	}

	/**
	 * Save the config data received from the manialink
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handleSaveConfigAction(array $callback, Player $player) {
		foreach ($this->menus as $menu) {
			$menu->saveConfigData($callback[1], $player);
		}
	}

	/**
	 * Unset the player if he opened another Main Widget
	 *
	 * @param Player $player
	 * @param string $openedWidget
	 */
	public function handleWidgetOpened(Player $player, $openedWidget) {
		if ($openedWidget !== self::MENU_NAME) {
			$player->destroyCache($this, self::CACHE_MENU_SHOWN);
		}
	}

	/**
	 * Handle ManialinkPageAnswer Callback
	 *
	 * @param array $callback
	 */
	public function handleManialinkPageAnswer(array $callback) {
		$actionId       = $callback[1][2];
		$boolSelectMenu = (strpos($actionId, self::ACTION_SELECTMENU) === 0);
		if (!$boolSelectMenu) {
			return;
		}

		$login  = $callback[1][1];
		$player = $this->maniaControl->playerManager->getPlayer($login);

		if ($player) {
			$actionArray = explode('.', $callback[1][2]);
			$this->showMenu($player, intval($actionArray[2]));
		}
	}
}