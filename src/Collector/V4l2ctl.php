<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;
use TweedeGolf\PrometheusClient\PrometheusException;

class V4l2ctl extends AbstractCollector
{
    public function isAvailable() {
        return $this->commandExists('v4l2-ctl');
    }

    /**
     * @return array
     */
    protected function getDevices() {
        return $this->config['devices'] ?? [['path' => '/dev/video0']];
    }

    protected function getCameraControlLabels() {
        return $this->getCommonLabels() + [
            'device_path' => null,
            'control_name' => null,
            'datatype' => null,
            'addr' => null,
            'type' => null,
        ];
    }
    

    public function collect(CollectorRegistry $registry)
    {
        $cameraControlLabels = $this->getCameraControlLabels();
        foreach ($this->getDevices() as $device) {
            $data = $this->retrieveMetrics($device['path']);
            
            if (empty($data)) {
                $this->log(sprintf('No data found for device %s', $device['path']), 'WARN');
            }
            
            /** @var array $values */
            foreach ($data as $controlName => $controlData) {
                $gauge = $this->getGauge($registry, 'v4l2ctl_cameracontrol');
                foreach ($controlData['values'] as $type => $v) {
                    $gauge->set(
                        (float) $v,
                        array_merge($cameraControlLabels, [
                            'device_path' => $device['path'],
                            'control_name' => $controlName,
                            'datatype' => $controlData['datatype'],
                            'addr' => $controlData['addr'],
                            'type' => $type
                        ])
                    );
                }
            }
        }
    }

    protected function getGauge(CollectorRegistry $registry, $name) {
        try {
            $gauge = $registry->getGauge($name);
        } catch(PrometheusException $e) {
            $registry->createGauge(
                $name,
                array_keys($this->getCameraControlLabels()),
                null,
                null,
                CollectorRegistry::DEFAULT_STORAGE,
                true
            );
            $gauge = $registry->getGauge($name);
        }

        return $gauge;
    }

    /**
     * @param string $devicePath
     * @return array
     */
    protected function retrieveMetrics($devicePath) {
        $command = sprintf('v4l2-ctl -d %s --all', escapeshellarg($devicePath));
        exec($command, $output, $rc);
        if ($rc) {
            $this->log(sprintf("$command\n%s", json_encode($output, JSON_PRETTY_PRINT)), 'ERROR');

            return [];
        }

        $return = [];
        foreach ($output as $line) {
            if (preg_match('/^\s*(?P<control_name>\w+) (?P<addr>0x[0-9a-f]{8}) \((?P<datatype>\w+)\)\s+: (?P<values>.*)$/', $line, $matches)) {
                $values = array_reduce(explode(' ', $matches['values']), function($carry, $item) {
                    list($v, $k) = explode('=', $item);
                    
                    return array_merge($carry, [$v => $k]);
                }, []);

                if (!array_key_exists($matches['control_name'], $return)) {
                    $return[$matches['control_name']] = [
                        'datatype' => $matches['datatype'],
                        'addr' => $matches['addr'],
                        'values' => []
                    ];
                }
                foreach ($values as $k => $v) {
                    if (is_numeric($v)) {
                        $return[$matches['control_name']]['values'][$k] = $v;
                    }
                }

            }
        }

        return $return;
    }
}