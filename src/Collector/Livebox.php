<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class Livebox extends AbstractCollector
{
    public const DEFAULT_LIVEBOX_URL = 'http://192.168.1.1/';

    public function isAvailable() {
        return !empty($this->config['sysbus_path'])
            && !empty($this->config['sysbus_settings']);
    }

    public function collect(CollectorRegistry $registry)
    {
        if (empty($this->config['sysbus_settings']['url_livebox'])) {
            $this->config['sysbus_settings']['url_livebox'] = self::DEFAULT_LIVEBOX_URL;
        }

        try {
            $this->collectWifiStatus($registry);
        } catch (\Throwable $e) {
            $this->log('Cannot retrieve WIFI status: ' . $e->getMessage(), 'ERROR');
        }
        try {
            $this->collectDeviceInfo($registry);
        } catch (\Throwable $e) {
            $this->log('Cannot retrieve device info: ' . $e->getMessage(), 'ERROR');
        }
        try {
            $this->collectIPv6Info($registry);
        } catch (\Throwable $e) {
            $this->log('Cannot retrieve IPv6 info: ' . $e->getMessage(), 'ERROR');
        }
        try {
            $this->collectHosts($registry);
        } catch (\Throwable $e) {
            $this->log('Cannot retrieve hosts status: ' . $e->getMessage(), 'ERROR');
        }
        try {
            $this->collectDslInfo($registry);
        } catch (\Throwable $e) {
            $this->log('Cannot retrieve DSL info: ' . $e->getMessage(), 'ERROR');
        }
    }

    protected function collectWifiStatus(CollectorRegistry $registry) {
        $result = json_decode($this->execCommand('-wifistate'), true);

        $registry->createGauge(
            'livebox_wifi_status',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $registry->getGauge('livebox_wifi_status')
            ->set($result['status']['Status'] ? 1 : 0, $this->getCommonLabels());
    }

    protected function collectDeviceInfo(CollectorRegistry $registry) {
        $result = json_decode($this->execCommand('sysbus.DeviceInfo:get'), true);
        if (!is_array($result) || !isset($result['status']) || !is_array($result['status'])) {
            throw new Exception("Cannot process command output.");
        }

        // Software Version
        $labels = $this->getCommonLabels() + ['version' => $result['status']['SoftwareVersion']];
        $registry->createGauge(
            'livebox_software_version',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_software_version')
            ->set(1, $labels);

        // Uptime
        $labels = $this->getCommonLabels();
        $registry->createGauge(
            'livebox_uptime',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_uptime')
            ->set($result['status']['UpTime'], $labels);

        // External IPv4 Address
        if (!empty($result['status']['ExternalIPAddress'])) {
            $labels = $this->getCommonLabels() + ['external_ip_address' => $result['status']['ExternalIPAddress']];
            $value = 1;
        } else {
            $labels = $this->getCommonLabels() + ['external_ip_address' => ''];
            $value = 0;
        }
        $registry->createGauge(
            'livebox_external_ip_address',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_external_ip_address')
            ->set($value, $labels);
    }

    protected function collectIPv6Info(CollectorRegistry $registry) {
        $result = json_decode($this->execCommand('NMC.IPv6:get'), true);
        if (!is_array($result) || !isset($result['data']) || !is_array($result['data'])) {
            throw new Exception("Cannot process command output.");
        }

        if (!empty($result['data']['IPv6Address'])) {
            $labels = $this->getCommonLabels() + ['ipv6_address' => $result['data']['IPv6Address']];
            $value = 1;
        } else {
            $labels = $this->getCommonLabels() + ['ipv6_address' => ''];
            $value = 0;
        }
        $registry->createGauge(
            'livebox_ipv6_address',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_ipv6_address')
            ->set($value, $labels);
    }

    protected function collectHosts(CollectorRegistry $registry) {
        $labels = [
            'mac' => null,
            'interface' => null,
            'name' => null,
            'addr' => null,
        ];

        $registry->createGauge(
            'livebox_hosts',
            array_keys($this->getCommonLabels() + $labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $result = json_decode($this->execCommand('sysbus.Hosts.Host:get'), JSON_OBJECT_AS_ARRAY);
        if (!is_array($result) || !isset($result['status']) || !is_array($result['status'])) {
            throw new Exception("Cannot process command output.");
        }
        foreach ($result['status'] as $hostInfo) {
            $labels = $this->getCommonLabels() + [
                'mac' => $hostInfo['MACAddress'] ?? null,
                'interface' => $hostInfo['InterfaceType'] ?? null,
                'name' => $hostInfo['HostName'] ?? null,
                'addr' => $hostInfo['IPAddress'] ?? null
            ];
            $registry->getGauge('livebox_hosts')
                ->set($hostInfo['Active'] ? 1 : 0, $labels);
        }
    }

    protected function collectDslInfo(CollectorRegistry $registry) {
        $result = json_decode($this->execCommand('sysbus.Devices.Device.HGW:get'), true);
        if (!is_array($result) || !isset($result['status']) || !is_array($result['status'])) {
            throw new Exception("Cannot process command output.");
        }

        if (!in_array($result['status']['LinkType'], ['dsl', 'gpon'])) {
            // Don't know atm if the other metrics are usable when not in DSL mode, so exit early
            $this->log('Unsupported LinkType for DSL info retreval: ' . $result['status']['LinkType'], E_WARNING);
            return;
        }

        $utcTz = new \DateTimeZone('UTC');
        $labels = [
            'way' => null,
        ];

        // Current rates
        $registry->createGauge(
            'livebox_dsl_rate',
            array_keys($this->getCommonLabels() + $labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_dsl_rate')
            ->set($result['status']['DownstreamCurrRate'], $this->getCommonLabels() + [
                'way' => 'down'
            ]);
        $registry->getGauge('livebox_dsl_rate')
            ->set($result['status']['UpstreamCurrRate'], $this->getCommonLabels() + [
                'way' => 'up'
            ]);

        // Max rates
        $registry->createGauge(
            'livebox_dsl_rate_max',
            array_keys($this->getCommonLabels() + $labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_dsl_rate_max')
            ->set($result['status']['DownstreamMaxBitRate'], $this->getCommonLabels() + [
                'way' => 'down'
            ]);
        $registry->getGauge('livebox_dsl_rate_max')
            ->set($result['status']['UpstreamMaxBitRate'], $this->getCommonLabels() + [
                'way' => 'up'
            ]);

        // Last change (timestamp)
        $registry->createGauge(
            'livebox_dsl_lastchange',
            array_keys($this->getCommonLabels() + ['date_iso' => null]),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_dsl_lastchange')
            ->set(
                (new \DateTimeImmutable($result['status']['LastChanged'], $utcTz))->getTimestamp(),
                $this->getCommonLabels() + ['date_iso' => $result['status']['LastChanged']]
            );

        // Last change (time)
        $registry->createGauge(
            'livebox_dsl_lastchange_time',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_dsl_lastchange_time')
            ->set(
                (new \DateTimeImmutable('now', $utcTz))->getTimestamp()
                - (new \DateTimeImmutable($result['status']['LastChanged'], $utcTz))->getTimestamp(),
                $this->getCommonLabels()
            );

        // Internet Up
        $registry->createGauge(
            'livebox_internet_up',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_internet_up')
            ->set($result['status']['Internet'] ? 1 : 0, $this->getCommonLabels());

        // Telephony Up
        $registry->createGauge(
            'livebox_telephony_up',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_telephony_up')
            ->set($result['status']['Telephony'] ? 1 : 0, $this->getCommonLabels());

        // IPTV Up
        $registry->createGauge(
            'livebox_iptv_up',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_iptv_up')
            ->set($result['status']['IPTV'] ? 1 : 0, $this->getCommonLabels());

        // DSL Up (aggregation)
        $labels = [
            'linkstate' => null,
            'connectionstate' => null,
        ];
        $registry->createGauge(
            'livebox_dsl_up',
            array_keys($this->getCommonLabels() + $labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $upConditions = $result['status']['Active']
            && $result['status']['Internet']
            && $result['status']['LinkState'] === 'up'
            && $result['status']['ConnectionState'] === 'Bound'
            && $result['status']['DownstreamCurrRate'] > 0
            && $result['status']['UpstreamCurrRate'] > 0
        ;
        $registry->getGauge('livebox_dsl_up')
            ->set(
                $upConditions ? 1 : 0,
                $this->getCommonLabels() + [
                    'linkstate' => $result['status']['LinkState'],
                    'connectionstate' => $result['status']['ConnectionState'],
                ]
            );
    }

    /**
     * @return array
     */
    protected function getCommonLabels() {
        return parent::getCommonLabels() + [
            'livebox_host' => parse_url($this->config['sysbus_settings']['url_livebox'], PHP_URL_HOST)
        ];
    }

    /**
     * @param string|array $cmd
     * @return string
     */
    protected function execCommand($cmd) {
        if (is_array($cmd)) {
            $cmd = implode(' ', $cmd);
        }

        $fullCmd = sprintf(
            '%s -url %s -user %s -password %s -lversion %s %s',
            $this->config['sysbus_path'],
            $this->config['sysbus_settings']['url_livebox'],
            $this->config['sysbus_settings']['user_livebox'] ?? 'admin',
            $this->config['sysbus_settings']['password_livebox'],
            $this->config['sysbus_settings']['version_livebox'] ?? 'lb4',
            $cmd
        );

        exec(
            $fullCmd,
            $output,
            $rc
        );
        $output = implode("\n", $output);

        if ($rc) {
            throw new Exception('sysbus returned an error code: ' . $rc);
        }

        return $output;
    }
}
