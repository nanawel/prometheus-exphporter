<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class Livebox extends AbstractCollector
{
    public function isAvailable() {
        return !empty($this->config['sysbus_path'])
            && !empty($this->config['sysbus_settings']);
    }

    public function collect(CollectorRegistry $registry)
    {
        if (empty($this->config['sysbus_settings']['url_livebox'])) {
            $this->config['sysbus_settings']['url_livebox'] = 'http://192.168.1.1/';
        }

        $this->collectWifiStatus($registry);
        $this->collectDeviceInfo($registry);
        $this->collectHosts($registry);
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

        // External IP Address
        $labels = $this->getCommonLabels() + ['external_ip_address' => $result['status']['ExternalIPAddress']];
        $registry->createGauge(
            'livebox_external_ip_address',
            array_keys($labels),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        $registry->getGauge('livebox_external_ip_address')
            ->set(1, $labels);
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
