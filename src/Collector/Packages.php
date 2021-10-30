<?php

namespace Arrakis\Exphporter\Collector;

use TweedeGolf\PrometheusClient\CollectorRegistry;

class Packages extends AbstractCollector
{
    const DEFAULT_DBUPDATE_DELAY = 60 * 60 * 24;  // 1 day

    public function isAvailable() {
        return method_exists($this, 'getUpgradablePackages_' . $this->getDistroFlavour());
    }

    public function collect(CollectorRegistry $registry)
    {
        $upgradablePackages = $this->{'getUpgradablePackages_' . $this->getDistroFlavour()}();

        $registry->createGauge(
            'packages_upgradable',
            array_keys($this->getCommonLabels()),
            null,
            null,
            CollectorRegistry::DEFAULT_STORAGE,
            true
        );

        $registry->getGauge('packages_upgradable')
            ->set(count($upgradablePackages), $this->getCommonLabels());
    }

    protected function getUpgradablePackages_debian() {
        if ($this->shouldUpdate()) {
            $this->saveState(['last_update' => time()]);
            exec('apt update');
        }
        exec('apt list --upgradable', $output);

        return $output;
    }

    protected function getUpgradablePackages_ubuntu() {
        return $this->getUpgradablePackages_debian();
    }

    protected function getUpgradablePackages_raspbian() {
        return $this->getUpgradablePackages_debian();
    }

    protected function getUpgradablePackages_arch() {
        if ($this->shouldUpdate()) {
            $this->saveState(['last_update' => time()]);
            exec('pacman -Sy');
        }
        exec('pacman -Qu', $output);

        return $output;
    }

    /**
     * @return bool
     */
    protected function shouldUpdate() {
        $delay = !isset($this->config['dbupdate_freq'])
            ? self::DEFAULT_DBUPDATE_DELAY
            : $this->config['dbupdate_freq'];
        if (!$delay) {
            return false;
        }

        $lastUpdate = $this->loadState()['last_update'] ?? 0;

        return  time() - $lastUpdate > $delay;
    }

    protected function getDistroFlavour() {
        $flavour = $this->config['distro_flavour'] ?? '';
        if (!$flavour) {
            if (is_readable('/etc/os-release')) {
                $osRelease = parse_ini_file('/etc/os-release');
                if (!empty($osRelease['ID'])) {
                    $flavour = preg_replace('/[^\w]/', '_', $osRelease['ID']);
                }
            }
        }

        return strtolower($flavour);
    }
}
