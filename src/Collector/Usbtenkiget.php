<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

/**
 * @see https://www.raphnet.net/electronique/usbtenki/index_en.php
 */
class Usbtenkiget extends AbstractCollector
{
    public const DEFAULT_USBTENKIGET_BIN_PATH = 'usbtenkiget';

    public function collect(CollectorRegistry $registry)
    {
        $registry->createGauge(
            'usbtenkiget_sensor',
            array_keys($this->getGaugeLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );
        foreach ($this->config['sensors'] ?? [] as $sensorId => $sensorConfig) {
            foreach ($sensorConfig['channels'] ?? [0] as $channelId) {
                $cmdArgs = $this->getCmdArgs($sensorConfig, $channelId);
                $output = null;

                $cmd = implode(' ', $cmdArgs);
                exec($cmd, $output, $rc);
                if ($rc !== 0) {
                    // usbtenkiget always returns code 255 for no reason, so just ignore that
                }
                $value = current($output);

                if (!is_numeric($value)) {
                    $this->log('Unable to parse usbtenkiget output: ' . implode("\n", $output), 'ERROR');
                    $this->log('Command was: ' . $cmd, 'ERROR');
                } else {
                    $registry->getGauge('usbtenkiget_sensor')
                      ->set($value, $this->getGaugeLabels($sensorConfig, $sensorId, $channelId));
                }
            }
        }
    }

    protected function getGaugeLabels(array $sensorConfig = [], $sensorId = 0, $channelId = 0) {
        return $this->getCommonLabels() + [
            'sensor_id' => $sensorId,
            'sensor_name' => $sensorConfig['name'] ?? "sensor_{$sensorId}_{$channelId}",
            'sensor_channel' => $channelId
        ];
    }

    /**
     * @param array $sensorConfig
     * @param int|null $channelId
     * @return string[]
     */
    public function getCmdArgs(array $sensorConfig, $channelId = 0) {
        $cmd = [$this->getBinPath()];
        
        $cmd[] = '-i';
        $cmd[] = $channelId;

        if (($this->config['units']['temperature'] ?? 'default') !== 'default') {
            $cmd[] = '-T';
            $cmd[] = $this->config['units']['temperature'];
        }
        if (($this->config['units']['pressure'] ?? 'default') !== 'default') {
            $cmd[] = '-P';
            $cmd[] = $this->config['units']['pressure'];
        }
        if (($this->config['units']['frequency'] ?? 'default') !== 'default') {
            $cmd[] = '-F';
            $cmd[] = $this->config['units']['frequency'];
        }
        if (($this->config['units']['length'] ?? 'default') !== 'default') {
            $cmd[] = '-M';
            $cmd[] = $this->config['units']['length'];
        }

        if (($sensorConfig['usb_serial'] ?? 'auto') !== 'auto') {
            $cmd[] = '-s';
            $cmd[] = $sensorConfig['usb_serial'];
        }

        return $cmd;
    }

    /**
     * @return string|null
     */
    public function getBinPath() {
        return $this->config['usbtenkiget_path'] ?? self::DEFAULT_USBTENKIGET_BIN_PATH;
    }
}
