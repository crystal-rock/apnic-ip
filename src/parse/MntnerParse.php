<?php


namespace apnicParse\src\parse;


class MntnerParse extends BaseApnicParse
{
    protected $init_parse_dict = [
        'mntner' => [],
        'country' => [],
        'descr' => [],
        'admin_c' => [],
        'tech_c' => [],
        'upd_to' => [],
        'mnt_nfy' => [],
        'auth' => [],
        'mnt_by' => [],
        'remarks' => [],
    ];

    protected $init_parse_must_key = 'mntner';

    protected function setFile()
    {
        $this->download_url = $this->anpic_mntner_url;
        $this->download_path = $this->db_file_dir . '/apnic.db.mntner.gz';
        $this->unzip_path = $this->db_file_dir . '/apnic.db.mntner';
        $this->parse_path = $this->db_file_dir . '/mntner-parse.txt';
    }
}