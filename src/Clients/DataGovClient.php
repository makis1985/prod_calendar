<?php
/**
 * Клиент для получения данных производственных календарей (от сервиса открытых данных России Data.gov.ru)
 */

namespace Devmakis\ProdCalendar\Clients;

use Devmakis\ProdCalendar\Clients\Exceptions\ClientCacheException;
use Devmakis\ProdCalendar\Clients\Exceptions\ClientException;
use Devmakis\ProdCalendar\Day;
use Devmakis\ProdCalendar\Holiday;
use Devmakis\ProdCalendar\Month;
use Devmakis\ProdCalendar\PreHolidayDay;
use Devmakis\ProdCalendar\Weekend;
use Devmakis\ProdCalendar\Year;

class DataGovClient implements IClient, ICachedClient
{
    /**
     * Корневой адрес запроса к API сервиса
     */
    const ROOT_URL = 'http://data.gov.ru/api/json/dataset/7708660670-proizvcalendar/version/20151123T183036/content/';

    /**
     * Ключи данных API сервиса
     */
    const API_DATA_KEYS = [
        'YEAR'                  => 'Год/Месяц',
        'MONTHS'                => [
            '01' => 'Январь',
            '02' => 'Февраль',
            '03' => 'Март',
            '04' => 'Апрель',
            '05' => 'Май',
            '06' => 'Июнь',
            '07' => 'Июль',
            '08' => 'Август',
            '09' => 'Сентябрь',
            '10' => 'Октябрь',
            '11' => 'Ноябрь',
            '12' => 'Декабрь',
        ],
        'TOTAL_WORKING_DAYS'    => 'Всего рабочих дней',
        'TOTAL_NONWORKING_DAYS' => 'Всего праздничных и выходных дней',
        'NUM_WORKING_HOURS_40'  => 'Количество рабочих часов при 40-часовой рабочей неделе',
        'NUM_WORKING_HOURS_36'  => 'Количество рабочих часов при 36-часовой рабочей неделе',
        'NUM_WORKING_HOURS_24'  => 'Количество рабочих часов при 24-часовой рабочей неделе',
    ];

    /**
     * Разделитель дней в данных API сервиса
     */
    const API_DELIMITER_DAYS = ',';

    /**
     * Метка предпраздничного дня в данных API сервиса
     */
    const API_LABEL_PRE_HOLIDAY = '*';

    /**
     * Нерабочие праздничные дни
     * согласно Статье 112 "Трудовой кодекс Российской Федерации" от 30.12.2001 N 197-ФЗ (ред. от 05.02.2018)
     * @link https://www.consultant.ru/document/cons_doc_LAW_34683/98ef2900507766e70ff29c0b9d8e2353ea80a1cf/#dst102376
     */
    const NONWORKING_HOLIDAYS = [
        '01.01' => 'Новогодние каникулы',
        '02.01' => 'Новогодние каникулы',
        '03.01' => 'Новогодние каникулы',
        '04.01' => 'Новогодние каникулы',
        '05.01' => 'Новогодние каникулы',
        '06.01' => 'Новогодние каникулы',
        '07.01' => 'Рождество Христово',
        '08.01' => 'Новогодние каникулы',
        '23.02' => 'День защитника Отечества',
        '08.03' => 'Международный женский день',
        '01.05' => 'Праздник Весны и Труда',
        '09.05' => 'День Победы',
        '12.06' => 'День России',
        '04.11' => 'День народного единства',
    ];

    /**
     * @var string ключ для работы с API сервиса
     */
    protected $token;

    /**
     * @var string полный адрес запроса к API
     */
    protected $requestUrl;

    /**
     * @var int количество секунд ожидания при попытке соединения
     */
    protected $timeout;

    /**
     * @var int максимально позволенное количество секунд для выполнения cURL-функций
     */
    protected $connectTimeout;

    /**
     * Данные от API сервиса
     * @var array
     */
    protected $data;

    /**
     * Путь к файлу, в котором сохраненны данные от API сервиса
     * @var string
     */
    protected $cacheFile;

    /**
     * Время жизни кэша в секундах (по умолчанию 15 суток)
     * @var int
     */
    protected $cacheLifetime = 60 * 60 * 24 * 15;

    /**
     * Client constructor.
     * @param string $token
     */
    public function __construct($token)
    {
        $this->token = $token;
        $this->requestUrl = self::ROOT_URL . '?access_token=' . $this->token;
        $this->timeout = 1;
        $this->connectTimeout = 1;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param int $connectTimeout
     */
    public function setConnectTimeout($connectTimeout)
    {
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @return string
     */
    public function getCacheFile()
    {
        return $this->cacheFile;
    }

    /**
     * @param string $cacheFile
     */
    public function setCacheFile($cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    /**
     * @return int
     */
    public function getCacheLifetime()
    {
        return $this->cacheLifetime;
    }

    /**
     * @param int $cacheLifetime
     */
    public function setCacheLifetime($cacheLifetime)
    {
        $this->cacheLifetime = (int)$cacheLifetime;
    }

    /**
     * Запросит данные у API сервиса
     * @throws ClientException
     * @return string $response
     */
    public function request()
    {
        $curl = curl_init($this->requestUrl);
        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR    => true,
        ]);
        $response = curl_exec($curl);

        if ($response === false) {
            $errorCode = curl_errno($curl);
            $errorMessage = curl_error($curl);
            curl_close($curl);

            throw new ClientException('cURL request get error - ' . $errorMessage, $errorCode);
        }

        curl_close($curl);

        return $response;
    }

    /**
     * Получить данные от API сервиса
     * @return array
     * @throws ClientException
     */
    public function getData()
    {
        if ($this->data) {
            return $this->data;
        }

        try {
            $result = $this->readCache();
        } catch (ClientException $e) {
            $result = $this->request();
        }

        $this->data = json_decode($result, true);

        if (!$this->data) {
            throw new ClientException('Data is empty or incorrect');
        }

        return $this->data;
    }

    /**
     * Получить год производственного календаря
     * @param $numberY
     * @return Year
     * @throws ClientException
     */
    public function getYear($numberY)
    {
        $numberY = (string)$numberY;

        foreach ($this->getData() as $row) {
            $responseYear = (string)$row[DataGovClient::API_DATA_KEYS['YEAR']];

            if ($responseYear !== $numberY) {
                continue;
            }

            $months = [];

            foreach (DataGovClient::API_DATA_KEYS['MONTHS'] as $numberM => $keyM) {
                $days = explode(DataGovClient::API_DELIMITER_DAYS, $row[$keyM]);
                $nonWorkingDays = [];
                $preHolidayDays = [];

                foreach ($days as $numberD) {
                    if (strpos($numberD, DataGovClient::API_LABEL_PRE_HOLIDAY) !== false) {
                        $numberD = str_replace(DataGovClient::API_LABEL_PRE_HOLIDAY, '', $numberD);
                        $preHolidayDay = new PreHolidayDay($numberD, $numberM, $numberY);
                        $preHolidayDays[$preHolidayDay->getNumberD()] = $preHolidayDay;

                        continue;
                    }

                    $nonWorkingDay = new Day($numberD, $numberM, $numberY);
                    $keyHoliday = $nonWorkingDay->getNumberD() . '.' . $nonWorkingDay->getNumberM();

                    if (array_key_exists($keyHoliday, self::NONWORKING_HOLIDAYS)) {
                        $nonWorkingDay = new Holiday($numberD, $numberM, $numberY);
                        $nonWorkingDay->setDescription(self::NONWORKING_HOLIDAYS[$keyHoliday]);
                    } else {
                        $nonWorkingDay = new Weekend($numberD, $numberM, $numberY);
                    }

                    $nonWorkingDays[$nonWorkingDay->getNumberD()] = $nonWorkingDay;
                }

                $month = new Month($numberM, $numberY, $nonWorkingDays, $preHolidayDays);
                $months[$month->getNumberM()] = $month;
            }

            $calendar = new Year($numberY, $months);
            $calendar->setNumWorkingHours40($row[DataGovClient::API_DATA_KEYS['NUM_WORKING_HOURS_40']]);
            $calendar->setNumWorkingHours36($row[DataGovClient::API_DATA_KEYS['NUM_WORKING_HOURS_36']]);
            $calendar->setNumWorkingHours24($row[DataGovClient::API_DATA_KEYS['NUM_WORKING_HOURS_24']]);

            return $calendar;
        }

        throw new ClientException("Production calendar is not found for «{$numberY}» year");
    }

    /**
     * Записать в кэш (в файл)
     * @throws ClientCacheException
     * @throws ClientException
     */
    public function writeCache()
    {
        if (!$this->getCacheFile()) {
            throw new ClientCacheException('The path to the cached file is not set');
        }

        $contents = file_put_contents($this->getCacheFile(), $this->request());

        if ($contents === false) {
            $error = error_get_last();
            throw new ClientCacheException($error['message']);
        } elseif ($contents === 0) {
            throw new ClientCacheException('file_put_contents: number of bytes written to the file = 0');
        }
    }

    /**
     * Прочитать из кэша (из файла)
     * @return string
     * @throws ClientCacheException
     * @throws ClientException
     */
    public function readCache()
    {
        if (!$this->getCacheFile()) {
            throw new ClientCacheException('The path to the cached file is not set');
        }

        if (!file_exists($this->getCacheFile())) {
            $this->writeCache();
        } else {
            $timeLastUpdateFile = filemtime($this->getCacheFile());

            if ($timeLastUpdateFile < time() - $this->cacheLifetime) {
                $this->writeCache();
            }
        }

        $result = file_get_contents($this->getCacheFile());

        if ($result === false) {
            $error = error_get_last();
            throw new ClientCacheException($error['message']);
        }

        return $result;
    }
}
