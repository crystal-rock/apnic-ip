<?php


namespace apnicParse\src\parse;


class AutNumParse extends BaseApnicParse
{
    protected $init_parse_dict = [
        'aut_num' => [],
        'country' => [],
        'as_name' => [],
        'descr' => [],
        'admin_c' => [],
        'tech_c' => [],
        'abuse_c' => [],
        'mnt_lower' => [],
        'mnt_routes' => [],
        'mnt_by' => [],
        'mnt_irt' => [],
        'remarks' => [],
        'last_modified' => [],
    ];

    protected $init_parse_must_key = 'aut_num';

    protected function setFile()
    {
        $this->download_url = $this->anpic_autnum_url;
        $this->download_path = $this->db_file_dir . '/apnic.db.aut-num.gz';
        $this->unzip_path = $this->db_file_dir . '/apnic.db.aut-num';
        $this->parse_path = $this->db_file_dir . '/aut-num-parse.txt';
    }

    /**
     * 生成地区文件
     * @author LuoBao
     * 2023/6/14 18:25
     * @return array
     */
    public function generateRegionDict()
    {
        try {
            if (!file_exists($this->parse_path)) {
                return $this->returnStatus(false, "{$this->parse_path}文件不存在");
            }

            $dict_file = $this->db_file_dir . '/aut-num-country-map.txt';
            $parse_aut_num_handle = fopen($this->parse_path, 'r');
            $dict_handle = fopen($dict_file, 'wb');
            while (!feof($parse_aut_num_handle)) {
                $content = fgets($parse_aut_num_handle);
                if (!$content) {
                    continue;
                }

                $content_arr = explode($this->split_character, $content);
                list($country_name, $province_name) = $this->matchCountry($content_arr[1]);
                if ($content_arr[1] == 'CN' && !$province_name) {
                    $province_name = $this->matchChinaProvince($content_arr[3]);
                }

                fwrite($dict_handle, "{$content_arr['0']}|{$country_name}|{$province_name}\n");
            }

            return $this->returnStatus(true,'生成文件成功', $dict_file);
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 替换文件
     * @author LuoBao
     * 2023/6/13 13:38
     */
    public function replaceDict()
    {
        try {
            copy($this->db_file_dir . '/aut-num-country-map.txt', $this->dict_file_dir . '/aut-num-country-map.txt');
            return $this->returnStatus(true, '覆盖成功');
        } catch (\Exception $e) {
            return $this->returnStatus(false,$e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }
}