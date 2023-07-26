<?php


namespace apnicParse\src\parse;


abstract class BaseApnicParse
{
    protected $anpic_autnum_url = 'https://ftp.apnic.net/apnic/whois/apnic.db.aut-num.gz';
    protected $anpic_route_url = 'https://ftp.apnic.net/apnic/whois/apnic.db.route.gz';
    protected $anpic_inetnum_url = 'https://ftp.apnic.net/apnic/whois/apnic.db.inetnum.gz';
    protected $anpic_mntner_url = 'https://ftp.apnic.net/apnic/whois/apnic.db.mntner.gz';

    protected $split_character = '#@@@#';
    protected $split_character_sub = '&@@@&';

    protected $db_file_dir = '';
    protected $download_url = '';
    protected $download_path = '';
    protected $unzip_path = '';
    protected $parse_path = '';

    protected $init_parse_dict = [];

    protected $dict_file_dir = '';

    protected $a_length = 30;
    protected $v_length = 4;
    protected $pre_ip_data_length = 0;
    protected $dat_ip_db = '';
    protected $first_record_length = 4;

    public function __construct($db_file_dir = '')
    {
        if (!$db_file_dir) {
            $this->db_file_dir = realpath(__DIR__ . '/../db');
        } else {
            $this->db_file_dir = trim($db_file_dir, '/\\');
        }

        $this->db_file_dir .= '/' . date('Y-m-d');
        if (!is_dir($this->db_file_dir)) {
            mkdir($this->db_file_dir, 0777, true);
        }

        $this->setFile();

        $this->pre_ip_data_length = 2 * $this->v_length + $this->a_length;
        $this->dict_file_dir = __DIR__ . '/../dict/';
        $this->dat_ip_db = __DIR__ . '/../db/ip-db.dat';
    }

    protected function setFile() {}

    protected function returnStatus($code = false, $msg = '失败', $data = [])
    {
        return ['status' => $code, 'msg' => $msg, 'data' => $data];
    }

    /**
     * 下载文件
     * @author LuoBao
     * 2023/6/8 15:49
     * @param string $download_url
     * @param string $save_path
     * @return array
     */
    protected function curlDownloadFile($download_url = '', $save_path = '')
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $download_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_NOBODY, false);
        $response = curl_exec($curl);

        if (curl_getinfo($curl, CURLINFO_HTTP_CODE) == 200) {
            $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE); //头信息size
            $body = substr($response, $header_size);
            if (!@file_put_contents($save_path, $body)) {
                return $this->returnStatus(false, '文件下载失败');
            }

            return $this->returnStatus(true, '文件下载成功');
        }
        curl_close($curl);

        return $this->returnStatus(false, '下载文件请求失败');
    }

    protected function parseData(&$info_arr)
    {
        return true;
    }

    protected function dataSort()
    {
        return true;
    }

    /**
     * 下载文件并解压
     * @author LuoBao
     * 2023/6/8 17:31
     */
    protected function downloadAndUnzipFile()
    {
        if (file_exists($this->download_path)) {
            unlink($this->download_path);
        }
        if (file_exists($this->unzip_path)) {
            unlink($this->unzip_path);
        }
        $ret = $this->curlDownloadFile($this->download_url, $this->download_path);
        if (!$ret['status']) {
            return $ret;
        }

        $this->unzipGz($this->download_path, $this->unzip_path);

        return $this->returnStatus(true, '文件下载并解压成功');
    }

    /**
     * 解压gz文件
     * @author LuoBao
     * 2023/6/8 15:49
     * @param string $gz_file
     * @param string $unzip_file
     * @return bool
     */
    protected function unzipGz($gz_file = '', $unzip_file = '')
    {
        if (!$gz_file) {
            $gz_file = $this->download_path;
        }
        if (!$unzip_file) {
            $unzip_file = $this->unzip_path;
        }

        $buffer_size = 4096;
        $gz_file_resource = gzopen($gz_file, 'rb');
        $unzip_file_resource = fopen($unzip_file, 'wb');
        while (!gzeof($gz_file_resource)) {
            fwrite($unzip_file_resource, gzread($gz_file_resource, $buffer_size));
        }
        gzclose($gz_file_resource);
        fclose($unzip_file_resource);

        return true;
    }

    /**
     * 中国地区省份匹配
     * @author LuoBao
     * 2023/6/13 13:22
     * @param string $descr
     * @return mixed|string
     */
    public function matchChinaProvince($descr = '')
    {
        static $china_city_map;

        if (!$china_city_map) {
            //中国城市数据
            $file_china_city_map = explode("\n", file_get_contents($this->dict_file_dir . 'inetnum-mnt-by-province-map.txt'));
            $china_city_map = [];
            foreach ($file_china_city_map as $_val) {
                if (!$_val) {
                    continue;
                }
                $china_city_map[] = explode('###', $_val);
            }
        }

        $match_city = '';
        foreach ($china_city_map as $_key => $_val) {
            $_pattern = "/($_val[1])/i";
            if (preg_match($_pattern, $descr)) {
                $match_city = $_val[0];
                break;
            }
        }

        return $match_city;
    }

    protected function matchCountry($country_code = '')
    {
        $country_code = strtoupper($country_code);
        static $country_map;

        if (!$country_map) {
            //国家数据
            $file_country_map = explode("\n", file_get_contents($this->dict_file_dir . 'country-map.txt'));
            $country_map = [];
            foreach ($file_country_map as $_key => $_val) {
                if (!$_val) {
                    continue;
                }
                $_val_arr = explode('|', $_val);
                $country_map[$_val_arr[0]] = $_val_arr[1];
            }
        }

        if (!$country_code) {
            return ['', ''];
        }

        if ($country_code == 'TW') {
            return ['中国', '台湾'];
        } elseif ($country_code == 'MO') {
            return ['中国', '澳门'];
        } elseif ($country_code == 'HK') {
            return ['中国', '香港'];
        } else {
            return [$country_map[$country_code] ?? '', ''];
        }
    }

    /**
     * 解析文件
     * @author LuoBao
     * 2023/6/8 17:36
     * @param string $country
     * @param bool $is_download
     * @return array
     */
    public function parseFile($country = '', $is_download = true)
    {
        try {
            ini_set('memory_limit', '2G');
            if ($is_download) {
                $download_ret = $this->downloadAndUnzipFile();
                if (!$download_ret['status']) {
                    return $download_ret;
                }
            }

            $info_arr = $init_find_arr = $this->init_parse_dict;
            $last_key = '';

            $file_handle = fopen($this->unzip_path, 'r');
            $parse_handle = fopen($this->parse_path, 'wb');
            while (!feof($file_handle)) {
                $content = fgets($file_handle);
                if (strpos($content, '#') === 0) {
                    continue;
                }

                if ($content == "\n" || $content == '') {
                    if (!$info_arr[$this->init_parse_must_key]) {
                        continue;
                    }
                    if ($country && $country != $info_arr['country']) {
                        continue;
                    }

                    //子业务数据处理
                    if (!$this->parseData($info_arr)) {
                        continue;
                    }

                    foreach ($info_arr as $_k => $_v) {
                        if (is_array($_v)) {
                            $info_arr[$_k] = implode($this->split_character_sub, $_v);
                        }
                    }
                    fwrite($parse_handle, implode($this->split_character, $info_arr) . "\n");
                    $info_arr = $this->init_parse_dict;

                    $last_key = '';
                    continue;
                }

                $content = trim($content);
                $content_split = explode(': ', $content);
                if (count($content_split) == 1) {
                    if (count($content_split) == 1 && strripos($content, ':') + 1 == strlen($content)) {
                        continue;
                    }
                    $_string = trim($content_split[0]);
                } else {
                    $last_key = str_replace('-', '_', trim($content_split[0]));
                    $_string = trim($content_split[1]);
                }

                if (!isset($info_arr[$last_key])) {
                    continue;
                }

                if (count($content_split) == 1) {
                    $info_arr[$last_key][count($info_arr[$last_key]) - 1] .= ' ' . $_string;
                } else {
                    $info_arr[$last_key][] = $_string;
                }
            }

            fclose($parse_handle);
            fclose($file_handle);

            $this->dataSort();

            return $this->returnStatus(true,'解析文件成功', $this->parse_path);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 返回解析数据对应的字典
     * @author LuoBao
     * 2023/6/8 17:29
     * @return array
     */
    public function getInitParseDict()
    {
        return array_keys($this->init_parse_dict);
    }

    /**
     * 返回一级分割符
     * @author LuoBao
     * 2023/6/8 17:29
     * @return string
     */
    public function getCharacter()
    {
        return $this->split_character;
    }

    /**
     * 返回二级级分割符
     * @author LuoBao
     * 2023/6/8 17:29
     * @return string
     */
    public function getCharacterSub()
    {
        return $this->split_character_sub;
    }

    /**
     * 返回解析文件地址
     * @author LuoBao
     * 2023/6/8 17:30
     * @return string
     */
    public function getParseFile()
    {
        return $this->parse_path;
    }
}