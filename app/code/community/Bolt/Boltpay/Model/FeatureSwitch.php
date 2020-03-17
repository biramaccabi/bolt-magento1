<?php

/**
 * Bolt magento plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2020 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Class Bolt_Boltpay_Model_FeatureSwitch
 *
 * The Magento Model class that provides features switches related utility methods
 *
 */
class Bolt_Boltpay_Model_FeatureSwitch extends Bolt_Boltpay_Model_Abstract
{
    /**
     * Set flag to true when bolt plugin is updated and we need to update features
     */
    public static $shouldUpdateFeatureSwitches = false;

    // Switches field names
    const VAL_KEY = 'value';
    const DEFAULT_VAL_KEY = 'defaultValue';
    const ROLLOUT_KEY = 'rolloutPercentage';

    /**
     * Every feature switch added here should have a corresponding helper
     * in GeneralTrait
     */
    const BOLT_ENABLED_SWITCH_NAME = 'M1_BOLT_ENABLED';

    const COOKIE_NAME = 'BoltFeatureSwitchId';

    /**
     * @var array Bolt features.
     */
    private $switches;

    /**
     * @var array Default values for Bolt features
     */
    private $defaultSwitches;

    /**
     * Bolt_Boltpay_Model_FeatureSwitch constructor.
     */
    final public function __construct()
    {
        $this->defaultSwitches = array(
            self::BOLT_ENABLED_SWITCH_NAME => array(
                self::VAL_KEY => true,
                self::DEFAULT_VAL_KEY => false,
                self::ROLLOUT_KEY => 100
            ),
        );
    }

    /**
     * This method gets feature switches from Bolt and updates the local DB with
     * the latest values. To be used in upgrade data and webhooks.
     *
     * @return bool true if feature switches updates successfully, false otherwise
     */
    public function updateFeatureSwitches()
    {
        try {
            $switchesResponse = $this->boltHelper()->getFeatureSwitches();
        } catch (Exception $e) {
            // We already created bugsnag about exception
            return false;
        }
        $switchesData = @$switchesResponse->data->plugin->features;

        if (empty($switchesData)) {
            return false;
        }

        $switches = array();
        foreach ($switchesData as $switch) {
            $switches[$switch->name] = array(
                self::VAL_KEY => $switch->value,
                self::DEFAULT_VAL_KEY => $switch->defaultValue,
                self::ROLLOUT_KEY => $switch->rolloutPercentage
            );
        }
        $this->switches = $switches;
        Mage::getModel('core/config')->saveConfig('payment/boltpay/featureSwitches', json_encode($switches));
        Mage::getModel('core/config')->cleanCache();
        return true;
    }

    /**
     * Read feature switches from database if we didn't it before
     */
    protected function readSwitches()
    {
        if (isset($this->switches)) {
            return;
        }
        $this->switches = json_decode(Mage::getStoreConfig('payment/boltpay/featureSwitches'), true);
    }

    /**
     * Get unique ID from cookie or generate it and save it in cookie and session
     *
     * @return string
     */
    protected function getUniqueUserId()
    {
        $boltFeatureSwitchId = Mage::getSingleton('core/cookie')->get(SELF::COOKIE_NAME);
        if (!$boltFeatureSwitchId) {
            $boltFeatureSwitchId = uniqid("BFS", true);
            Mage::getSingleton('core/cookie')->set(SELF::COOKIE_NAME, $boltFeatureSwitchId, true);
        }
        return $boltFeatureSwitchId;
    }

    /**
     * This method returns if a feature switch is enabled for a user.
     * The way this is computed is as follows:
     * - get unique user id (from cookie)
     * - salt it with switch name (to have different values for the same user but different switches)
     * - calculate crc32 on salted string
     * - two last digit of crc32 is pseudo-random sequence,
     * we can use to identify if we need to enable feature switch or not
     *
     * @param string $switchName
     * @param int $rolloutPercentage
     *
     * @return bool
     */
    protected function isMarkedForRollout($switchName, $rolloutPercentage)
    {
        $boltFeatureSwitchId = $this->getUniqueUserId();
        $saltedString = $boltFeatureSwitchId . '-' . $switchName;
        $position = crc32($saltedString) % 100;

        return $position < $rolloutPercentage;
    }

    /**
     * Fetch feature switch object (value, defaultValue, rolloutPercentage) by switch name
     *
     * @param $switchName string
     *
     * @return object
     *
     * @throws Exception if default value for switch doesn't exists
     */
    protected function getFeatureSwitchValueByName($switchName)
    {
        $this->readSwitches();
        if (!isset($this->defaultSwitches[$switchName])) {
            throw new Exception('Unknown feature switch');
        }
        if (isset($this->switches[$switchName])) {
            return $this->switches[$switchName];
        }
        return $this->defaultSwitches[$switchName];
    }

    /**
     * This returns if the switch is enabled.
     *
     * @param string $switchName name of the switch
     *
     * @return bool
     *
     * @throws Exception
     */
    public function isSwitchEnabled($switchName)
    {
        $switch = $this->getFeatureSwitchValueByName($switchName);
        switch ($switch[self::ROLLOUT_KEY]) {
            case 0:
                return $switch[self::DEFAULT_VAL_KEY];
            case 100:
                return $switch[self::VAL_KEY];
            default:
                $isMarkedForRollout = $this->isMarkedForRollout($switchName, $switch[self::ROLLOUT_KEY]);
                return $isMarkedForRollout ? $switch[self::VAL_KEY] : $switch[self::DEFAULT_VAL_KEY];
        }
    }
}