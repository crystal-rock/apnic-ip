<?php


namespace apnicParse\src\parse;


class RouteParse extends BaseApnicParse
{
    protected $init_parse_dict = [
        'ip1_long' => [],
        'ip2_long' => [],
        'route' => [],
        'ip' => [],
        'origin' => [],
        'country' => [],
        'descr' => [],
    ];

    protected $init_parse_must_key = 'route';

    /**
     * 设置文件路径
     * @author LuoBao
     * 2023/6/13 13:40
     */
    protected function setFile()
    {
        $this->download_url = $this->anpic_route_url;
        $this->download_path = $this->db_file_dir . '/apnic.db.route.gz';
        $this->unzip_path = $this->db_file_dir . '/apnic.db.route';
        $this->parse_path = $this->db_file_dir . '/route-parse.txt';
    }

    protected function parseData(&$info_arr)
    {
        $ip_range = $info_arr['route'][0];
        $_ip_arr = explode('/', $ip_range);
        $_ip_1 = ip2long($_ip_arr[0]);
        $_ip_2 = $_ip_1 + 2 ** (32 - $_ip_arr[1]) - 1;
        $info_arr['ip'] = $_ip_arr[0] . '-' . long2ip($_ip_2);
        $info_arr['ip1_long'] = $_ip_1;
        $info_arr['ip2_long'] = $_ip_2;

        return true;
    }

    /**
     * IP排序并去重
     * @author LuoBao
     * 2023/6/13 13:32
     * @return bool
     */
    protected function dataSort()
    {
        $file_data = file($this->parse_path);
        natsort($file_data);
        file_put_contents($this->parse_path, implode("", $file_data));
        unset($file_data);

        $tmp_file_path = $this->db_file_dir . '/route-parse.tmp.txt';
        $parse_handle = fopen($this->parse_path, 'r');
        $tmp_handle = fopen($tmp_file_path, 'wb');
        $last_ip_1 = 0;
        $last_ip_2 = 0;
        while (!feof($parse_handle)) {
            $content = fgets($parse_handle);

            if ($content == "\n" || $content == '') {
                continue;
            }
            $content_arr = explode($this->split_character, $content);

            //IP段包含在在上一个IP段内，跳出
            if ($content_arr[0] >= $last_ip_1 && $content_arr[1] <= $last_ip_2) {
                continue;
            }

            $_current_ip1 = $content_arr[0];
            $_current_ip2 = $content_arr[1];
            //比前一个IP段长，只保留长多出来的IP段
            if ($content_arr[0] == $last_ip_1) {
                $_current_ip1 = $last_ip_2 + 1;
                if ($_current_ip1 > $_current_ip2) {
                    continue;
                }
            }

            $last_ip_1 = $content_arr[0];
            $last_ip_2 = max($_current_ip2, $content_arr[1]);

            $content_arr[0] = $_current_ip1;
            $content_arr[1] = $_current_ip2;
            $content_arr[3] = long2ip($_current_ip1) . '-' . long2ip($_current_ip2);
            fwrite($tmp_handle, implode($this->split_character, $content_arr));
        }
        fclose($parse_handle);
        fclose($tmp_handle);

        unlink($this->parse_path);
        rename($tmp_file_path, $this->parse_path);
        return true;
    }

    /**
     * 生成查询文件库
     * @author LuoBao
     * 2023/6/13 13:21
     * @return array
     */
    public function generateIpDb()
    {
        try {
            $parse_ip_file = $this->db_file_dir . '/route-parse.txt';
            if (!file_exists($parse_ip_file)) {
                return $this->returnStatus(false, "{$parse_ip_file}文件不存在");
            }

            //自治系统号省份数据
            $file_aut_num_province_map = explode("\n", file_get_contents($this->dict_file_dir . 'aut-num-province-map.txt'));
            $aut_num_province_map = [];
            foreach ($file_aut_num_province_map as $_key => $_val) {
                if (!$_val) {
                    continue;
                }
                $_val_arr = explode('|', $_val);
                $aut_num_province_map[$_val_arr[0]] = $_val_arr[1];
            }

            //自治系统号国家数据
            $file_aut_num_country_map = explode("\n", file_get_contents($this->dict_file_dir . 'aut-num-country-map.txt'));
            $aut_num_country_map = [];
            foreach ($file_aut_num_country_map as $_key => $_val) {
                if (!$_val) {
                    continue;
                }
                $_val_arr = explode('|', $_val);
                $aut_num_country_map[$_val_arr[0]] = [$_val_arr[1], $_val_arr[2]];
            }

            //运营商数据
            $file_aut_num_isp_map = explode("\n", file_get_contents($this->dict_file_dir . 'aut-num-isp-map.txt'));
            $aut_num_isp_map = [];
            foreach ($file_aut_num_isp_map as $_key => $_val) {
                if (!$_val) {
                    continue;
                }
                $_val_arr = explode('|', $_val);
                $aut_num_isp_map[$_val_arr[0]] = $_val_arr[1];
            }

            $db_ip_file = $this->db_file_dir . '/ip-db.dat';

            $parse_ip_handle = fopen($parse_ip_file, 'r');
            $parse_db_handle = fopen($db_ip_file, 'wb');
            $counter = 0;
            while (!feof($parse_ip_handle)) {

                $content = fgets($parse_ip_handle);
                $content_arr = explode($this->split_character, $content);
                if (count($content_arr) < 4) {
                    continue;
                }

                //ip段处理
                $_ip_range = $content_arr[3];
                $_ip_arr = explode('-', $_ip_range);
                $_ip_1 = ip2long($_ip_arr[0]);
                $_ip_2 = ip2long($_ip_arr[1]);

                //地区处理
                $_country = strtoupper($content_arr[5]);
                $_aut_num = $content_arr[4];
                //根据国家编号获取国家和省份
                list($_country_name, $_province_name) = $this->matchCountry($_country);
                //中国地区获取省份
                if ($_country == 'CN' || $_country == '') {
                    //根据描述获取省份
                    $_province_name = $this->matchChinaProvince($content_arr[6]);
                    if (!$_province_name && isset($aut_num_province_map[$_aut_num])) {
                        //没找到省份，查看AS编号中有没有标记的省份
                        $_province_name = $aut_num_province_map[$_aut_num];
                        $_country_name = '中国';
                    }
                    //根据aut-num描述和国家编号，匹配是否有省份
                    if (!$_province_name && isset($aut_num_country_map[$_aut_num])) {
                        $_province_name = $aut_num_country_map[$_aut_num][1];
                    }
                    if ($_country == 'CN' || $_province_name) {
                        $_country_name = '中国';
                    }
                }

                $_inline_arr = [];
                $_inline_arr['country'] = $_country ?: '-';
                $_inline_arr['countryName'] = $_country_name ?: '-';
                $_inline_arr['provinceName'] = $_province_name ?: '-';
                $_inline_arr['isp'] = $aut_num_isp_map[$_aut_num] ?? '-';

                //转二进制存储
                $region_data_str = iconv('UTF-8', 'GBK', implode('|', $_inline_arr));
                $bin_str = pack('V', $_ip_1) . pack('V', $_ip_2);
                $bin_str .= pack('A' . $this->a_length, $region_data_str);

                //写入文件
                fwrite($parse_db_handle, $bin_str);
                $counter++;
            }

            fseek($parse_db_handle, 0);
            fwrite($parse_db_handle, pack('V', $counter * $this->pre_ip_data_length));

            fclose($parse_ip_handle);
            fclose($parse_db_handle);

            return $this->returnStatus(true,'生成dat文件成功', $db_ip_file);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 替换IP查询库文件
     * @author LuoBao
     * 2023/6/13 13:38
     */
    public function replaceIpDb()
    {
        try {
            copy($this->db_file_dir . '/ip-db.dat', $this->dat_ip_db);
            return $this->returnStatus(true, '覆盖成功');
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }
}