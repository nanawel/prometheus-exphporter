<?php

namespace Arrakis\Exphporter\Collector;

abstract class AbstractCollector implements CollectorInterface
{
    /** array */
    protected $config;

    public function init(array $config) {
        $this->config = $config;

        return $this;
    }

    public function isAvailable() {
        return true;
    }

    /**
     * @return array
     */
    protected function getCommonLabels() {
        return [
            'exphporter_host' => gethostname()
        ];
    }

    /**
     * @param mixed $data
     */
    protected function saveState($data) {
        file_put_contents($this->getStateFile(), json_encode($data));

        return $this;
    }

    /**
     * @return mixed
     */
    protected function loadState() {
        if (is_file($file = $this->getStateFile())) {
            return json_decode(file_get_contents($file), JSON_OBJECT_AS_ARRAY);
        }

        return null;
    }

    /**
     * @param string $scrapeName
     * @return mixed
     */
    protected function loadScrapeState($scrapeName) {
        return $this->loadState()[$scrapeName] ?? [];
    }

    /**
     * @param string $scrapeName
     * @param mixed $data
     */
    protected function saveScrapeState($scrapeName, $data) {
        $state = $this->loadState() ?? [];
        $state[$scrapeName] = $data;
        $this->saveState($state);
    }

    /**
     * @return string
     */
    protected function getId() {
        return preg_replace('/[^\w]/', '_', static::class);
    }

    /**
     * @return string
     */
    protected function getStateFile() {
        return EXPHPORTER_BASE_DIR . '/data/' . $this->getId() . '.state.json';
    }

    /**
     * @param string $message
     * @param string $level
     */
    protected function log($message, $level = 'DEBUG') {
        file_put_contents(
            EXPHPORTER_BASE_DIR . '/data/app.log',
            sprintf("%s [%s] %s\n", date('c'), $level, is_string($message) ? $message : json_encode($message)),
            FILE_APPEND
        );
    }

    /**
     * @param string $cmd
     * @return bool
     */
    protected function commandExists($cmd) {
        exec(sprintf('command -v %s', escapeshellarg($cmd)), $output, $rc);

        return $rc === 0;
    }
}
