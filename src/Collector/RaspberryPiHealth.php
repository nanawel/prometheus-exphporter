<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;
use TweedeGolf\PrometheusClient\PrometheusException;

class RaspberryPiHealth extends AbstractCollector
{
    const DEFAULT_VCGENCMD_PATH = '/opt/vc/bin/vcgencmd';
    const DEFAULT_METRICS = [
        'throttled' => true,
        'temp' =>  true,
        'clock' => [
            'arm', 'core', 'h264', 'isp', 'v3d', 'uart', 'pwm', 'emmc', 'pixel', 'vec', 'hdmi', 'dpi'
        ],
        'volts' => [
            'core', 'sdram_c', 'sdram_i', 'sdram_p'
        ]
    ];

    const METRICS_CMD = [
        'throttled' => 'get_throttled',
        'temp'      => 'measure_temp',
        'clock'     => 'measure_clock',
        'volts'     => 'measure_volts',
    ];

    public function isAvailable() {
        return $this->commandExists($this->getVcgencmdPath());
    }

    public function getVcgencmdPath() {
        return $this->config['vcgencmd_path'] ?? self::DEFAULT_VCGENCMD_PATH;
    }

    public function getMetrics() {
        return $this->config['metrics'] ?? self::DEFAULT_METRICS;
    }

    public function collect(CollectorRegistry $registry)
    {
        foreach ($this->getMetrics() as $metric => $args) {
            $this->retrieveMetrics($registry, $metric, $args);
        }
    }

    protected function getGauge(CollectorRegistry $registry, $name, array $labels = []) {
        try {
            $gauge = $registry->getGauge($name);
        } catch (PrometheusException $e) {
            $registry->createGauge(
                $name,
                array_keys($this->getCommonLabels() + $labels),
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
     * @param CollectorRegistry $registry
     * @param string $type
     * @param bool|string[] $args
     * @return array
     */
    protected function retrieveMetrics(CollectorRegistry $registry, $type, $args) {
        if (!is_array($args)) {
            $args = [$args];
        }
        foreach ($args as $arg) {
            if ($arg) {
                $command = sprintf(
                    '%s %s %s',
                    $this->getVcgencmdPath(),
                    self::METRICS_CMD[$type],
                    is_string($arg) ? $arg : ''
                );
                exec($command, $output, $rc);
                if ($rc || empty($output[0])) {
                    $this->log(sprintf("$command\n%s", json_encode($output, JSON_PRETTY_PRINT)), 'ERROR');
        
                    continue;
                }
        
                $processMethod = sprintf('process%s', ucfirst($type));
                $this->$processMethod($registry, current($output), $arg);
                unset($output);
            }
        }
    }

    /**
     * @param string $output
     * @param string $format
     * @return string
     */
    protected function valueFromOutput($output, $format = '%s') {
        $v = explode('=', $output);

        return sscanf($v[1], $format)[0];
    }

    /**
     * @param string $output
     * @param bool $enabled
     */
    protected function processThrottled(CollectorRegistry $registry, $output) {
        $v = bindec($this->valueFromOutput($output));
        $gauge = $this->getGauge($registry, "rpi_throttled")
            ->set($v, $this->getCommonLabels());
        
        $bits = [
            0x1 => 'under_voltage',
            0x2 => 'arm_freq',
            0x4 => 'throttling',
            0x8 => 'soft_temp_limit',
        ];
        
        foreach ($bits as $bit => $throttlingType) {
            $labels = ['throttling_type' => $throttlingType];
            $gauge = $this->getGauge($registry, "rpi_throttling_active", $labels);
            $gauge->set($v & $bit ? 1 : 0, $this->getCommonLabels() + $labels);
        }
        
        $bits = [
            0x10000 => 'under_voltage',
            0x20000 => 'arm_freq',
            0x40000 => 'throttling',
            0x80000 => 'soft_temp_limit',
        ];
        
        foreach ($bits as $bit => $throttlingType) {
            $labels = ['throttling_type' => $throttlingType];
            $gauge = $this->getGauge($registry, "rpi_throttling_has_occured", $labels);
            $gauge->set($v & $bit ? 1 : 0, $this->getCommonLabels() + $labels);
        }
    }

    /**
     * @param string $output
     * @param bool $enabled
     */
    protected function processTemp(CollectorRegistry $registry, $output) {
        $v = $this->valueFromOutput($output, "%f'C");
        $this->getGauge($registry, "rpi_temp")
            ->set($v, $this->getCommonLabels());
    }

    /**
     * @param string $output
     * @param string $clockType
     */
    protected function processClock(CollectorRegistry $registry, $output, $clockType) {
        $v = $this->valueFromOutput($output, "%f");
        $labels = ['clock_type' => $clockType];
        $this->getGauge($registry, "rpi_clock", $labels)
            ->set($v, $this->getCommonLabels() + $labels);
    }

    /**
     * @param string $output
     * @param string $voltsType
     */
    protected function processVolts(CollectorRegistry $registry, $output, $voltsType) {
        $v = $this->valueFromOutput($output, "%fV");
        $labels = ['volts_type' => $voltsType];
        $this->getGauge($registry, "rpi_volts", $labels)
            ->set($v, $this->getCommonLabels() + $labels);
    }
}