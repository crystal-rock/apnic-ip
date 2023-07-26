<?php


namespace apnicParse\src\parse;


class ApnicIpQuery extends BaseApnicParse
{
    protected $dat_ip_length = 0;
    protected $ip_db_handle = null;

    /**
     * 读取查询IP文件库
     * @author LuoBao
     * 2023/6/13 13:23
     */
    private function readIpDb()
    {
        $this->ip_db_handle = fopen($this->dat_ip_db, 'r');
        $this->dat_ip_length = unpack('Vlength', fread($this->ip_db_handle, $this->first_record_length))['length'];

        return $this->ip_db_handle;
    }

    /**
     * 查询IP位置
     * @author LuoBao
     * 2023/6/13 13:23
     * @param string $ip
     * @return float|int
     */
    private function searchIpSeek($ip = '')
    {
        $begin_number = 0;
        $max_number = $end_number = $this->dat_ip_length / $this->pre_ip_data_length;
        while ($begin_number != $max_number && $begin_number <= $end_number) {
            $mid = floor(($begin_number + $end_number) / 2);
            fseek($this->ip_db_handle,  $mid * $this->pre_ip_data_length + $this->first_record_length);
            //echo "$begin_number => $mid => $end_number\n";
            // 获取二分区域的上边界
            $begin_ip = unpack('Vip', (fread($this->ip_db_handle, $this->v_length)))['ip'];
            // 获取二分区域的下边界
            $end_ip = unpack('Vip', fread($this->ip_db_handle, $this->v_length))['ip'];
            //echo long2ip($begin_ip) . "=>>" . long2ip($end_ip) . "\n";
            /*if ($begin_number == $end_number) {
                if ($begin_ip < $ip && $ip <= $end_ip) {
                    return $mid * $this->pre_ip_data_length + $this->first_record_length;
                } else {
                    break;
                }
            } else*/if ($ip < $begin_ip) {
                // 目标IP在二分区域以上， 缩小搜索的下边界
                $end_number = $mid - 1;
            } else {
                if ($ip > $end_ip) {
                    // 目标IP在二分区域以下，缩小搜索的上边界
                    $begin_number = $mid + 1;
                } else {
                    // 目标IP在二分区域内，返回索引的偏移量
                    return $mid * $this->pre_ip_data_length + $this->first_record_length;
                }
            }
        }

        // 无法找到对应索引，返回最后一条记录的偏移量
        return $this->dat_ip_length;
    }

    private function parseDat($seek = 0)
    {
        //$seek = 1;
        //$seek = $this->pre_ip_data_length * 2;
        //print_r($seek);
        fseek($this->ip_db_handle, $seek);
        $ip1 = unpack('Vip1', fread($this->ip_db_handle, $this->v_length))['ip1'];
        fseek($this->ip_db_handle, $seek + $this->v_length);
        $ip2 = unpack('Vip2', fread($this->ip_db_handle, $this->v_length))['ip2'];
        fseek($this->ip_db_handle, $seek + 2 * $this->v_length);
        $region = unpack('A*region', fread($this->ip_db_handle, $this->a_length))['region'];
        //print_r(iconv('GBK', 'UTF-8', $region));

        print_r("$ip1 => $ip2 => $region");
        exit;
    }

    /**
     * 获取IP信息
     * @author LuoBao
     * 2023/6/13 13:24
     * @param string $ip
     * @return array|string[]
     */
    public function queryIp($ip = '')
    {
        try {
            $long_ip = ip2long($ip);
            $this->readIpDb();

            $ip_data = [
                'ip' => $ip,
                'country_name' => '',
                'province_name' => '',
                'isp' => '',
            ];

            $ip_seek = $this->searchIpSeek($long_ip);
            //print_r($this->dat_ip_length);
            //print_r($ip_seek);//exit;
            if ($ip_seek == $this->dat_ip_length) {
                return $this->returnStatus(true, '没查询到IP', $ip_data);
            }

            fseek($this->ip_db_handle, $ip_seek);
            $query_data = unpack('Vip1/Vip2/A*region', fread($this->ip_db_handle, $this->pre_ip_data_length));
            //print_r($query_data);
            if ($query_data) {
                $region_arr = explode('|', iconv('GBK', 'UTF-8', $query_data['region']));
                $ip_data = [
                    'start_ip' => long2ip($query_data['ip1']),
                    'end_ip' => long2ip($query_data['ip2']),
                    'ip' => $ip,
                    'country_name' => $region_arr[1] != '-' ? $region_arr[1] : '',
                    'province_name' => $region_arr[2] != '-' ? $region_arr[2] : '',
                    'isp' => $region_arr[3] != '-' ? $region_arr[3] : '',
                ];

                if (ip2long($ip) < $query_data['ip1'] || ip2long($ip) > $query_data['ip2']) {
                    print_r($ip_data);
                }
            }

            return $this->returnStatus(true, 'IP信息', $ip_data);
        } catch (\Exception $e) {
            echo $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage();exit;
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }
}