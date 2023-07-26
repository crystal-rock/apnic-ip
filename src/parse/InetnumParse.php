<?php


namespace apnicParse\src\parse;


class InetnumParse extends BaseApnicParse
{
    protected $init_parse_dict = [
        'ip1_long' => [],
        'ip2_long' => [],
        'inetnum' => [],
        'ip' => [],
        'org' => [],
        'country' => [],
        'descr' => [],
        'geoloc' => [],
        'netname' => [],
        'mnt_irt' => [],
        'mnt_by' => [],
    ];

    protected $init_parse_must_key = 'inetnum';

    /**
     * 设置文件路径
     * @author LuoBao
     * 2023/6/13 13:40
     */
    protected function setFile()
    {
        $this->download_url = $this->anpic_inetnum_url;
        $this->download_path = $this->db_file_dir . '/apnic.db.inetnum.gz';
        $this->unzip_path = $this->db_file_dir . '/apnic.db.inetnum';
        $this->parse_path = $this->db_file_dir . '/inetnum-parse.txt';
    }

    protected function parseData(&$info_arr)
    {
        $ip_range = $info_arr['inetnum'][0];
        $_ip_arr = explode('-', $ip_range);
        $_ip_1 = trim($_ip_arr[0]);
        $_ip_2 = trim($_ip_arr[1]);
        $info_arr['ip'] = $_ip_1 . '-' . $_ip_2;
        $info_arr['ip1_long'] = ip2long($_ip_1);
        $info_arr['ip2_long'] = ip2long($_ip_2);

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
        //return true;
        $file_data = file($this->parse_path);
        natsort($file_data);
        file_put_contents($this->parse_path, implode("", $file_data));
        unset($file_data);

        return true;

        $tmp_file_path = $this->db_file_dir . '/inetnum-parse.tmp.txt';
        $parse_handle = fopen($this->parse_path, 'r');
        $tmp_handle = fopen($tmp_file_path, 'wb');
        $last_ip_1 = 0;
        $last_ip_2 = 0;
        $keys_dict = $this->getInitParseDict();
        while (!feof($parse_handle)) {
            $content = fgets($parse_handle);

            if ($content == "\n" || $content == '') {
                continue;
            }
            $content_arr = explode($this->split_character, $content);
            if ($content_arr[0] == 0) {
                continue;
            }
            $content_map = [];
            foreach ($keys_dict as $_k => $_v) {
                $content_map[$_v] = $content_arr[$_k];
            }

            list($_ip1, $_ip2) = explode('-', $content_map['ip']);
            if (stripos($_ip1, '.0.0.0') && stripos($_ip2, '.255.255.255')) {
                continue;
            }

            //IP段包含在在上一个IP段内，跳出
            if ($content_map['ip1_long'] >= $last_ip_1 && $content_map['ip2_long'] <= $last_ip_2) {
                continue;
            }

            $_current_ip1 = $content_map['ip1_long'];
            $_current_ip2 = $content_map['ip2_long'];
            //比前一个IP段长，只保留长多出来的IP段
            if ($content_map['ip1_long'] == $last_ip_1) {
                $_current_ip1 = $last_ip_2 + 1;
                if ($_current_ip1 > $_current_ip2) {
                    continue;
                }
            }

            $last_ip_1 = $content_map['ip1_long'];
            $last_ip_2 = max($_current_ip2, $content_map['ip2_long']);

            $content_map['ip1_long'] = $_current_ip1;
            $content_map['ip2_long'] = $_current_ip2;
            $content_map['ip'] = long2ip($_current_ip1) . '-' . long2ip($_current_ip2);
            fwrite($tmp_handle, implode($this->split_character, $content_map));
        }
        fclose($parse_handle);
        fclose($tmp_handle);

        unlink($this->parse_path);
        rename($tmp_file_path, $this->parse_path);
        return true;
    }

    public function matchIsp($mnt_by = '')
    {
        static $isp_map;

        if (!$isp_map) {
            //中国城市数据
            $file_isp_map = explode("\n", file_get_contents($this->dict_file_dir . 'inetnum-mnt-by-isp-map.txt'));
            $isp_map = [];
            foreach ($file_isp_map as $_val) {
                if (!$_val) {
                    continue;
                }
                $isp_map[] = explode('###', $_val);
            }
        }

        $match_isp = '';
        foreach ($isp_map as $_key => $_val) {
            $_pattern = "/($_val[1])/i";
            if (preg_match($_pattern, $mnt_by)) {
                $match_isp = $_val[0];
                break;
            }
        }

        return $match_isp;
    }

    public function generateIpTxt()
    {
        ini_set('memory_limit', '3G');
        try {
            if (!file_exists($this->parse_path)) {
                return $this->returnStatus(false, "{$this->parse_path}文件不存在");
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

            $db_ip_text_file = $this->db_file_dir . '/ip-db-one.txt';

            $parse_ip_handle = fopen($this->parse_path, 'r');
            $parse_db_text_handle = fopen($db_ip_text_file, 'wb');
            $counter = 0;
            $keys_dict = $this->getInitParseDict();
            $group_ip_arr = [];
            $group_ip_last_key = '';
            while (!feof($parse_ip_handle)) {

                $content = fgets($parse_ip_handle);
                if (!$content) {
                    continue;
                }
                $content_arr = explode($this->split_character, $content);
                if (count($content_arr) != count($keys_dict)) {
                    continue;
                }
                $content_map = [];
                foreach ($keys_dict as $_k => $_v) {
                    if ($_v == 'country') {
                        $_country_arr = explode($this->getCharacterSub(), $content_arr[$_k]);
                        $content_map[$_v] = strtoupper($_country_arr[0]);
                    } else {
                        $content_map[$_v] = $content_arr[$_k];
                    }
                }

                //ip段处理
                $_ip_range = $content_map['ip'];
                $_ip_arr = explode('-', $_ip_range);

                $_ip_1_split = explode('.', $_ip_arr[0]);
                if (!$group_ip_arr) {
                    $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                    $group_ip_last_key = $_ip_1_split[0];
                    continue;
                }

                if ($group_ip_last_key == $_ip_1_split[0]) {
                    $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                    $group_ip_last_key = $_ip_1_split[0];
                    continue;
                }

                echo $group_ip_last_key . "\n";

                $deal_split_group = $this->ipGroup($group_ip_arr[$group_ip_last_key]);
                foreach ($deal_split_group as $_key => $_split_ip) {
                    //写入文件
                    fwrite($parse_db_text_handle, trim(implode($this->split_character, $_split_ip)) . "\n");
                    $counter++;
                }

               /* if ($group_ip_last_key == 58) {
                    break;
                }*/
                unset($group_ip_arr[$group_ip_last_key]);
                $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                $group_ip_last_key = $_ip_1_split[0];
            }

            //最后一次循环数据处理
            if ($group_ip_arr) {
                $deal_split_group = $this->ipGroup($group_ip_arr[$group_ip_last_key]);
                foreach ($deal_split_group as $_key => $_split_ip) {
                    //写入文件
                    fwrite($parse_db_text_handle, trim(implode($this->split_character, $_split_ip)) . "\n");
                    $counter++;
                }
            }
            fclose($parse_ip_handle);

            return $this->returnStatus(true,'生成dat文件成功', $db_ip_text_file);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$counter . '#' . $group_ip_last_key . '#' . $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    public function mergeIpTxt()
    {
        ini_set('memory_limit', '3G');
        try {
            if (!file_exists($this->parse_path)) {
                return $this->returnStatus(false, "{$this->parse_path}文件不存在");
            }

            $ip_db_one_file = $this->db_file_dir . '/ip-db-one.txt';
            $merge_ip_text_file = $this->db_file_dir . '/ip-db-two.txt';

            $ip_db_one_handle = fopen($ip_db_one_file, 'r');
            $merge_ip_text_handle = fopen($merge_ip_text_file, 'wb');
            $counter = 0;
            $group_ip_last_key = '';

            $last_ip_arr = [];
            $keys_dict = $this->getInitParseDict();
            while (!feof($ip_db_one_handle)) {

                $content = fgets($ip_db_one_handle);
                if (!$content) {
                    continue;
                }
                $content_arr = explode($this->split_character, $content);
                if (count($content_arr) != count($keys_dict)) {
                    continue;
                }
                $content_map = [];
                foreach ($keys_dict as $_k => $_v) {
                    $content_map[$_v] = $content_arr[$_k];
                }

                if ($last_ip_arr && $last_ip_arr['ip2_long'] + 1 == $content_map['ip1_long'] && $last_ip_arr['country'] == $content_map['country'] && $last_ip_arr['descr'] == $content_map['descr'] && $last_ip_arr['netname'] == $content_map['netname']) {
                    $last_ip_arr['ip2_long'] = $content_map['ip2_long'];
                    continue;
                }
                if (!$last_ip_arr) {
                    $last_ip_arr = $content_map;
                    continue;
                }

                $_save_ip = [];
                $_save_ip['ip1_long'] = $last_ip_arr['ip1_long'];
                $_save_ip['ip2_long'] = $last_ip_arr['ip2_long'];
                $_save_ip['ip'] = long2ip($last_ip_arr['ip1_long']) . '-' . long2ip($last_ip_arr['ip2_long']);
                $_save_ip['country'] = $last_ip_arr['country'];
                fwrite($merge_ip_text_handle, implode('|', $_save_ip) . "\n");
                $last_ip_arr = $content_map;
            }

            fclose($ip_db_one_handle);
            fclose($merge_ip_text_handle);

            return $this->returnStatus(true,'生成txt文件成功', $merge_ip_text_file);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$counter . '#' . $group_ip_last_key . '#' . $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 生成查询文件库
     * @author LuoBao
     * 2023/6/13 13:21
     * @return array
     */
    public function generateIpDb()
    {
        ini_set('memory_limit', '3G');
        try {
            if (!file_exists($this->parse_path)) {
                return $this->returnStatus(false, "{$this->parse_path}文件不存在");
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
            $db_ip_text_file = $this->db_file_dir . '/ip-db.txt';

            $parse_ip_handle = fopen($this->parse_path, 'r');
            $parse_db_handle = fopen($db_ip_file, 'wb');
            $parse_db_text_handle = fopen($db_ip_text_file, 'wb');
            $counter = 0;
            $keys_dict = $this->getInitParseDict();
            $group_ip_arr = [];
            $group_ip_last_key = '';
            while (!feof($parse_ip_handle)) {

                $content = fgets($parse_ip_handle);
                if (!$content) {
                    continue;
                }
                $content_arr = explode($this->split_character, $content);
                if (count($content_arr) != count($keys_dict)) {
                    continue;
                }
                $content_map = [];
                foreach ($keys_dict as $_k => $_v) {
                    $content_map[$_v] = $content_arr[$_k];
                }

                //ip段处理
                $_ip_range = $content_map['ip'];
                $_ip_arr = explode('-', $_ip_range);

                $_ip_1_split = explode('.', $_ip_arr[0]);
                if (!$group_ip_arr) {
                    $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                    $group_ip_last_key = $_ip_1_split[0];
                    continue;
                }

                if ($group_ip_last_key == $_ip_1_split[0]) {
                    $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                    $group_ip_last_key = $_ip_1_split[0];
                    continue;
                }

                echo $group_ip_last_key . "\n";

                $deal_split_group = $this->ipGroup($group_ip_arr[$group_ip_last_key]);
                foreach ($deal_split_group as $_key => $_split_ip) {
                    //地区处理
                    $_country = strtoupper($_split_ip['country']);
                    //根据国家编号获取国家和省份
                    list($_country_name, $_province_name) = $this->matchCountry($_country);
                    //中国地区获取省份
                    if ($_country == 'CN' && !$_province_name) {
                        //根据描述获取省份
                        if ($_split_ip['geoloc']) {
                            $_province_name = $_split_ip['geoloc'];
                        } else {
                            $_province_name = $this->matchChinaProvince($_split_ip['mnt_by'] . ' ' . $_split_ip['descr']);
                        }
                    }

                    $_inline_arr = [];
                    $_inline_arr['country'] = $_country ?: '-';
                    $_inline_arr['countryName'] = $_country_name ?: '-';
                    $_inline_arr['provinceName'] = $_province_name ?: '-';
                    $_inline_arr['isp'] = $this->matchIsp($_split_ip['mnt_by']);
                    /*if ($_country == 'CN' && $_inline_arr['isp']) {
                        print_r($_split_ip);
                        print_r($_inline_arr);
                        exit;
                    }*/

                    //转二进制存储
                    $region_data_str = iconv('UTF-8', 'GBK', implode('|', $_inline_arr));
                    $bin_str = pack('V', $_split_ip['ip1_long']) . pack('V', $_split_ip['ip2_long']);
                    $bin_str .= pack('A' . $this->a_length, $region_data_str);

                    //写入文件
                    //fwrite($parse_db_handle, $bin_str);
                    fwrite($parse_db_text_handle, $_split_ip['ip1_long'] . '|' . $_split_ip['ip2_long'] . '|' . $_split_ip['ip'] . '|' . implode('|', $_inline_arr) . "\n");
                    $counter++;
                }

                unset($group_ip_arr[$group_ip_last_key]);
                $group_ip_arr[$_ip_1_split[0]][] = $content_map;
                $group_ip_last_key = $_ip_1_split[0];
            }

            fseek($parse_db_handle, 0);
            fwrite($parse_db_handle, pack('V', $counter * $this->pre_ip_data_length));

            fclose($parse_ip_handle);
            fclose($parse_db_handle);

            return $this->returnStatus(true,'生成dat文件成功', $db_ip_file);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$counter . '#' . $group_ip_last_key . '#' . $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    public function generateIpDb2()
    {
        try {
            //$this->db_file_dir = 'E:\project\luobao\test-demo\extend\apnicParse\src\db\2023-07-20';
            $db_ip_text_file = $this->db_file_dir . '/ip-db.txt';
            if (!file_exists($db_ip_text_file)) {
                return $this->returnStatus(false, "{$db_ip_text_file}文件不存在");
            }


            $db_ip_file = $this->db_file_dir . '/ip-db.dat';
            $db_ip_text_file = $this->db_file_dir . '/ip-db.txt';

            $parse_db_handle = fopen($db_ip_file, 'wb');
            $parse_db_text_handle = fopen($db_ip_text_file, 'r');
            $counter = 0;

            fwrite($parse_db_handle, pack('V', 0));

            while (!feof($parse_db_text_handle)) {
                $content = trim(fgets($parse_db_text_handle));
                if (!$content) {
                    continue;
                }
                $content_arr = explode('|', $content);
                $content_arr[3] = strtoupper($content_arr[3]);

                list($country_name, $province_name) = $this->matchCountry($content_arr[3]);
                if (!$province_name) {
                    $province_name = $content_arr[5] ?? '';
                }

                $_inline_arr = [];
                $_inline_arr['country'] = $content_arr[3];
                $_inline_arr['countryName'] = $country_name;
                $_inline_arr['provinceName'] = $province_name;
                $_inline_arr['isp'] = $content_arr[6] ?? '';

                //转二进制存储
                $region_data_str = iconv('UTF-8', 'GBK', implode('|', $_inline_arr));
                $bin_str = pack('V', $content_arr[0]) . pack('V', $content_arr[1]);
                $bin_str .= pack('A' . $this->a_length, $region_data_str);

                //写入文件
                fwrite($parse_db_handle, $bin_str);
                $counter++;
            }

            fseek($parse_db_handle, 0);
            fwrite($parse_db_handle, pack('V', $counter * $this->pre_ip_data_length));

            print_r($counter * $this->pre_ip_data_length);

            fclose($parse_db_text_handle);
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
            return $this->returnStatus(true, '覆盖成功', [$this->db_file_dir . '/ip-db.dat', $this->dat_ip_db]);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 拆分IP段并组合
     * @author LuoBao
     * 2023/6/19 10:25
     * @param array $ip_arr
     * @param $ip_split_group
     * @return mixed
     */
    private function ipSplitIpGroup($ip_arr = [], $ip_split_group)
    {
        $history_split_group = [];
        foreach ($ip_arr as $_ik => $_iv) {
            if (stripos($_iv['ip'], '.0.0.0') !== false && stripos($_iv['ip'], '.255.255.255') !== false) {
                echo $_iv['ip'] . "\n";
                continue;
            }

            $_split_flag = false;
            if ($_ik > 0 && $_ik % 10000 == 0) {
                echo "{$_ik}\n";
            }

            $aaa = [];
            foreach ($ip_split_group as $_sk => $_sv) {
                $aaa = $_sv;
                if ($_sv['ip1_long'] == $_iv['ip1_long'] && $_sv['ip2_long'] == $_iv['ip2_long']) {
                    $ip_split_group[$_sk] = $_iv;
                    //已拆分IP段跟未处理IP段相等
                    $_split_flag = true;
                    break;
                } elseif ($_sv['ip1_long'] == $_iv['ip1_long'] || $_sv['ip2_long'] == $_iv['ip2_long']) {
                    //已拆分IP段起始IP和未处理IP段起始IP相同或者已拆分IP段结束IP和未处理IP段结束IP相同
                    $group_ip_arr = array_unique([$_sv['ip1_long'], $_sv['ip2_long'], $_iv['ip1_long'], $_iv['ip2_long']]);
                    sort($group_ip_arr);

                    if (count($group_ip_arr) == 2) {
                        if ($_iv['ip1_long'] == $group_ip_arr[0]) {
                            $split_1 = $_iv;
                            $split_2 = $_sv;
                            $split_1['ip1_long'] = $group_ip_arr[0];
                            $split_1['ip2_long'] = $group_ip_arr[0];
                            $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                            $split_2['ip1_long'] = $group_ip_arr[0] + 1;
                            $split_2['ip2_long'] = $group_ip_arr[1];
                            $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);
                        } else {
                            $split_1 = $_sv;
                            $split_2 = $_iv;
                            $split_1['ip1_long'] = $group_ip_arr[0];
                            $split_1['ip2_long'] = $group_ip_arr[1] - 1;
                            $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                            $split_2['ip1_long'] = $group_ip_arr[1];
                            $split_2['ip2_long'] = $group_ip_arr[1];
                            $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);
                        }

                        $_split_flag = true;
                        unset($ip_split_group[$_sk]);
                        $ip_split_group[$split_1['ip']] = $split_1;
                        $ip_split_group[$split_2['ip']] = $split_2;
                        break;
                    }

                    $group_ip_arr[3] = $group_ip_arr[2];
                    $group_ip_arr[2] = $group_ip_arr[1] + 1;

                    if ($group_ip_arr[1] == $_iv['ip2_long']) {
                        $split_1 = $_iv;
                        $split_2 = $_sv;
                    } else {
                        $split_1 = $_sv;
                        $split_2 = $_iv;
                    }

                    $split_1['ip1_long'] = $group_ip_arr[0];
                    $split_1['ip2_long'] = $group_ip_arr[1];
                    $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                    $split_2['ip1_long'] = $group_ip_arr[2];
                    $split_2['ip2_long'] = $group_ip_arr[3];
                    $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);

                    $_split_flag = true;
                    unset($ip_split_group[$_sk]);
                    $ip_split_group[$split_1['ip']] = $split_1;
                    $ip_split_group[$split_2['ip']] = $split_2;
                    break;
                } elseif ($_sv['ip1_long'] > $_iv['ip1_long'] && $_sv['ip2_long'] < $_iv['ip2_long']) {
                    //已拆分IP段包含在未处理IP段内
                    $split_1 = $_iv;
                    $split_1['ip1_long'] = $_iv['ip1_long'];
                    $split_1['ip2_long'] = $_sv['ip1_long'] - 1;
                    $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                    $split_2 = $_iv;
                    $split_2['ip1_long'] = $_sv['ip1_long'];
                    $split_2['ip2_long'] = $_sv['ip2_long'];
                    $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);

                    $split_3 = $_iv;
                    $split_3['ip1_long'] = $_sv['ip2_long'] + 1;
                    $split_3['ip2_long'] = $_iv['ip2_long'];
                    $split_3['ip'] = long2ip($split_3['ip1_long']) . '-' . long2ip($split_3['ip2_long']);

                    $_split_flag = true;
                    unset($ip_split_group[$_sk]);
                    $ip_split_group[$split_1['ip']] = $split_1;
                    $ip_split_group[$split_2['ip']] = $split_2;
                    $ip_split_group[$split_3['ip']] = $split_3;
                    break;
                } elseif ($_sv['ip1_long'] < $_iv['ip1_long'] && $_sv['ip2_long'] > $_iv['ip2_long']) {
                    //未处理IP段包含在已拆分IP段内
                    $split_1 = $_sv;
                    $split_1['ip1_long'] = $_sv['ip1_long'];
                    $split_1['ip2_long'] = $_iv['ip1_long'] - 1;
                    $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                    $split_2 = $_iv;

                    $split_3 = $_sv;
                    $split_3['ip1_long'] = $_iv['ip2_long'] + 1;
                    $split_3['ip2_long'] = $_sv['ip2_long'];
                    $split_3['ip'] = long2ip($split_3['ip1_long']) . '-' . long2ip($split_3['ip2_long']);

                    $_split_flag = true;
                    unset($ip_split_group[$_sk]);
                    $ip_split_group[$split_1['ip']] = $split_1;
                    $ip_split_group[$split_2['ip']] = $split_2;
                    $ip_split_group[$split_3['ip']] = $split_3;
                    break;
                } elseif ($_sv['ip1_long'] < $_iv['ip1_long'] && $_sv['ip2_long'] > $_iv['ip1_long']) {
                    //未拆分IP段起始位置，包含在已拆分IP段内
                    $split_1 = $_sv;
                    $split_1['ip1_long'] = $_sv['ip1_long'];
                    $split_1['ip2_long'] = $_iv['ip1_long'] - 1;
                    $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                    $split_2 = $_iv;
                    $split_2['ip1_long'] = $_iv['ip1_long'];
                    $split_2['ip2_long'] = $_sv['ip2_long'];
                    $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);

                    $split_3 = $_iv;
                    $split_3['ip1_long'] = $_sv['ip2_long'] + 1;
                    $split_3['ip2_long'] = $_iv['ip2_long'];
                    $split_3['ip'] = long2ip($split_3['ip1_long']) . '-' . long2ip($split_3['ip2_long']);

                    $_split_flag = true;
                    unset($ip_split_group[$_sk]);
                    $ip_split_group[$split_1['ip']] = $split_1;
                    $ip_split_group[$split_2['ip']] = $split_2;
                    $ip_split_group[$split_3['ip']] = $split_3;
                    break;
                } elseif ($_sv['ip1_long'] < $_iv['ip2_long'] && $_sv['ip2_long'] > $_iv['ip2_long']) {
                    //未拆分IP段结束位置，包含在已拆分IP段内
                    $split_1 = $_iv;
                    $split_1['ip1_long'] = $_iv['ip1_long'];
                    $split_1['ip2_long'] = $_sv['ip2_long'] - 1;
                    $split_1['ip'] = long2ip($split_1['ip1_long']) . '-' . long2ip($split_1['ip2_long']);

                    $split_2 = $_iv;
                    $split_2['ip1_long'] = $_sv['ip1_long'];
                    $split_2['ip2_long'] = $_iv['ip2_long'];
                    $split_2['ip'] = long2ip($split_2['ip1_long']) . '-' . long2ip($split_2['ip2_long']);

                    $split_3 = $_sv;
                    $split_3['ip1_long'] = $_iv['ip2_long'] + 1;
                    $split_3['ip2_long'] = $_sv['ip2_long'];
                    $split_3['ip'] = long2ip($split_3['ip1_long']) . '-' . long2ip($split_3['ip2_long']);

                    $_split_flag = true;
                    unset($ip_split_group[$_sk]);
                    $ip_split_group[$split_1['ip']] = $split_1;
                    $ip_split_group[$split_2['ip']] = $split_2;
                    $ip_split_group[$split_3['ip']] = $split_3;
                    break;
                }
            }

            if (!$_split_flag) {
                $ip_split_group[$_iv['ip']] = $_iv;
            }

            /*if ($_iv['ip1_long'] == 461117440) {
                echo "split\n";
                print_r($ip_split_group);
                echo "iv\n";
                print_r($_iv);
                echo "aaa\n";
                print_r($aaa);
                exit;
            }*/

            $sort_arr = array_column($ip_split_group, 'ip1_long');
            array_multisort($sort_arr, SORT_ASC, $ip_split_group, SORT_ASC);

            $ip_split_group = array_values($ip_split_group);
            $this->splitUniqueGroup($ip_split_group);

            if (count($ip_split_group) > 500) {
                for ($i = 0; $i < count($ip_split_group) - 500; $i++) {
                    $history_split_group[] = array_shift($ip_split_group);
                }
            }
        }

        foreach ($ip_split_group as $_key => $_val) {
            $history_split_group[] = $_val;
        }

        return $history_split_group;
    }

    /**
     * 已拆分IP，重复IP段处理
     * @author LuoBao
     * 2023/6/19 10:27
     * @param array $ip_split_group
     * @return bool
     */
    private function splitUniqueGroup(&$ip_split_group = [])
    {
        $repetition_group = [];
        foreach ($ip_split_group as $_fk => $_fv) {
            foreach ($ip_split_group as $_sk => $_sv) {
                if ($_fv['ip2_long'] > $_sv['ip2_long']) {
                    break;
                }
                if ($_fk != $_sk && ($_fv['ip1_long'] >= $_sv['ip1_long'] && $_fv['ip2_long'] <= $_sv['ip2_long'])) {
                    $repetition_group[$_fk] = $_fv;
                    unset($ip_split_group[$_fk]);
                }
            }
        }

        if (!$repetition_group) {
            return true;
        }

        $ip_split_group = $this->ipSplitIpGroup($repetition_group, $ip_split_group);

        if ($this->splitUniqueGroup($ip_split_group)) {
            return true;
        }

        return false;
    }

    /**
     * ip分组处理
     * @author LuoBao
     * 2023/6/19 10:27
     * @param array $ip_arr
     * @return array
     */
    public function ipGroup($ip_arr = [])
    {
        $ip_split_group = [];

        $start_time = time();
        $split_group = $this->ipSplitIpGroup($ip_arr, $ip_split_group);
        $time1 = time() - $start_time;
        $this->splitUniqueGroup($split_group);
        $time2 = time() - $start_time - $time1;

        if ($time1 > 10 || $time2 > 10) {
            echo  "##" . date('H:i:s', $start_time) . '到' . date("H:i:s") . "，遍历：{$time1} 去重：{$time2}\n";
            echo "##" . count($ip_arr) . "==>" . count($ip_split_group) . "\n\n";
        }

        return array_values($split_group);
    }
}