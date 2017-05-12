<?php

namespace ManiaControl\Manialinks;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Structures\ShootMania\Models\Position;
use ManiaControl\General\UsageInformationAble;
use ManiaControl\General\UsageInformationTrait;
use ManiaControl\ManiaControl;

/**
 * Class managing the Sidebar icons
 *
 * @api
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2017 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class SidebarMenuManager implements UsageInformationAble, CallbackListener {
	use UsageInformationTrait;

	/* Settings */
	const SETTING_SIDEBAR_POSX            = 'Sidebar X Position';
	const SETTING_SIDEBAR_POSY_SHOOTMANIA = 'Sidebar Y Position (Shootmania)';
	const SETTING_SIDEBAR_POSY_TRACKMANIA = 'Sidebar Y Position (Trackmania)';
	const SETTING_MENU_ITEMSIZE           = 'Size of menu items';

	const ORDER_ADMIN_MENU  = 10;
	const ORDER_PLAYER_MENU = 20;

	/* @var $maniaControl ManiaControl */
	private $maniaControl;
	private $menuEntries       = array();
	private $yPositions        = array();
	private $registeredClasses = array();

	/**
	 * SidebarMenuManager constructor.
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_SIDEBAR_POSX, 156);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_SIDEBAR_POSY_SHOOTMANIA, -17);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_SIDEBAR_POSY_TRACKMANIA, 17);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_MENU_ITEMSIZE, 6);
	}

	/**
	 * Returns Position of the Sidebar (PositionObject with x and y)
	 *
	 * @return Position
	 * @api
	 */
	public function getSidebarPosition() {
		$posX = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_SIDEBAR_POSX);

		if ($this->maniaControl->getMapManager()->getCurrentMap()->getGame() === 'sm') {
			$posY = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_SIDEBAR_POSY_SHOOTMANIA);
		} else {
			$posY = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_SIDEBAR_POSY_TRACKMANIA);
		}

		$pos = new Position();
		$pos->setX($posX);
		$pos->setY($posY);

		return $pos;
	}

	/**
	 * Returns PositionObject of a menu item of the sidebar, or null if it's not found
	 *
	 * @param string $id
	 * @return Position|null
	 * @api
	 */
	public function getEntryPosition($id) {
		$itemSize         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_MENU_ITEMSIZE);
		$itemMarginFactor = 1.2;
		$pos              = $this->getSidebarPosition();

		if (isset($this->yPositions[$id])) {
			$pos->setY($this->yPositions[$id]);
			return $pos;
		}

		foreach ($this->menuEntries as $entry) {
			if ($entry == $id) {
				$this->yPositions[$id] = $pos->getY();
				return $pos;
			}
			$pos->setY($pos->getY() - $itemSize * $itemMarginFactor);
		}

		$this->maniaControl->getErrorHandler()->triggerDebugNotice('SidebarMenuEntry id:' . $id . ' not found');
		return null;
	}


	/**
	 * Registers an Entry to the SidebarMenu
	 * Get the associated position with getEntryPosition($id)
	 *
	 * @param SidebarMenuEntryRenderable                          $render
	 * @param                                                     $order
	 * @param                                                     $id
	 * @return bool
	 * @api
	 */
	public function addMenuEntry(SidebarMenuEntryRenderable $render, $order, $id) {
		if (isset($this->menuEntries[$order])) {
			if ($this->menuEntries[$order] != $id) {
				return $this->addMenuEntry($render, $order + 1, $id);
			}
		}
		$this->menuEntries[$order] = $id;
		$this->yPositions          = array();
		ksort($this->menuEntries);


		$registered = false;
		foreach ($this->registeredClasses as $class) {
			$class->renderMenuEntry();
			if ($class == $render) {
				$registered = true;
			}
		}

		if (!$registered) {
			array_push($this->registeredClasses, $render);
			$render->renderMenuEntry();
		}

		return true;
	}


	/**
	 * @param SidebarMenuEntryRenderable                          $render
	 * @param                                                     $id
	 * @param bool                                                $unregisterClass
	 * @api
	 */
	public function deleteMenuEntry(SidebarMenuEntryRenderable $render, $id, $unregisterClass = false) {
		foreach ($this->menuEntries as $k => $entry) {
			if ($entry == $id) {
				unset($this->menuEntries[$k]);
				$this->yPositions = array();
			}
		}

			foreach ($this->registeredClasses as $k => $class) {
				if ($class == $render && $unregisterClass) {
					unset($this->registeredClasses[$k]);
				}else{
					$class->renderMenuEntry();
				}
			}


	}

}