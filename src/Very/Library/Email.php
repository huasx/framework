<?php namespace Very\Library;

/**
 * Created by PhpStorm.
 * User: 蔡旭东 fifsky@gmail.com
 * Date: 14-7-16
 * Time: 下午3:47
 */
class Email {

    /*邮件用户名*/
    public $mailUser;

    /*邮件密码*/
    public $mailPwd;

    /*邮件服务器地址*/
    public $server;

    /*邮件端口*/
    public $port;

    public $timeout;

    /*邮件编码*/
    public $charset;

    /*邮件发送者email,用于显示给接收者*/
    public $senderMail;

    /*发用者名称*/
    public $senderName;

    /*是否使用ssl安全操作*/
    public $useSSL;

    /*是否显示错误信息*/
    public $showError = 1;

    public $needLogin = 1;

    /*附件数组*/
    public $attachMent = array();

    public $failed = false;

    private static $smtpCon;
    private $stop = "\r\n";
    private $status = 0;


    public function __construct() {
        $config = config('email');

        $this->senderName = $this->senderMail = $this->mailUser = $config['smtp_user'];

        $this->charset = $config['charset'];
        $this->timeout = $config['smtp_timeout'];
        if ($this->mailUser == '') {
            $this->error('请配置好邮件登录用户名!');
        }

        $this->mailPwd = $config['smtp_pass'];

        if ($this->mailPwd == '') {
            $this->error('请配置好邮件登录密码!');
        }

        $this->server = $config['smtp_host'];
        if ($this->server == '') {
            $this->error('请配置好邮服务器地址!');
        }

        $this->port = $config['smtp_port'];
        if (!is_numeric($this->port)) {
            $this->error('请配置好邮服务器端口!');
        }

        $this->useSSL = $config['ssl'];
        /*ssl使用**/
        $server = $this->server;
        if ($this->useSSL == true) {
            $server = "ssl://" . $this->server;
        }

        self::$smtpCon = @fsockopen($server, $this->port, $errno, $errstr, 10);;

        if (!self::$smtpCon) {
            $this->error('SMTP服务器连接失败:'.$errno . $errstr);
        }


        socket_set_timeout(self::$smtpCon, 0, 250000);

        /*开始邮件指令*/
        $this->getStatus();
        $resp = true;
        $resp = $resp && $this->helo();
        if ($this->needLogin == '1') {
            $resp = $resp && $this->login();
        }

        if (!$resp) {
            $this->failed = true;
        }
    }

    static public function getInstance() {
        static $_instance = null;
        return $_instance ?: $_instance = new self;
    }

    public function from($email,$send_name){

        $this->senderMail = $email;
        $this->senderName = $send_name;
        return $this;
    }

    /*
    发送邮件
    @param string $to 接收邮件地址
    @title string $title 邮件标题
    @param string $msg 邮件主要内容
    */
    public function sendMail($to, $title = '',$msg) {

        if ($msg == '') {

            return false;
        }
        if (is_array($to)) {

            if ($to != null) {
                foreach ($to as $k => $e) {

                    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {

                        unset($to[$k]);
                    }
                }
            } else {
                return false;
            }

            if ($to == null) {
                return false;
            }

        } else {

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {

                return false;
            }

        }


        if (!self::$smtpCon) {
            return false;
        }

        $this->sendSmtpMsg('MAIL FROM:<' . $this->senderMail . '>');

        if (!is_array($to)) {
            $this->sendSmtpMsg('RCPT TO:<' . $to . '>');
        } else {

            foreach ($to as $k => $email) {
                $this->sendSmtpMsg('RCPT TO:<' . $email . '>');
            }
        }

        $this->sendSmtpMsg("DATA");


        if ($this->status != '354') {
            $this->error('请求发送邮件失败!');
            $this->failed = true;
            return false;
        }

        $msg = base64_encode($msg);
        $msg = str_replace($this->stop . '.', $this->stop . '..', $msg);
        $msg = substr($msg, 0, 1) == '.' ? '.' . $msg : $msg;

        if ($this->attachMent != null) {

            $headers = $this->mimeHeader($msg, $to, $title);
            $this->sendSmtpMsg($headers, false);

        } else {

            $headers = $this->mailHeader($to, $title);
            $this->sendSmtpMsg($headers, false);
            $this->sendSmtpMsg('', false);
            $this->sendSmtpMsg($msg, false);
        }
        $this->sendSmtpMsg('.'); //发送结束标识符

        if ($this->status != '250') {
            $this->failed = true;
            $this->error('邮件发送失败:'.$this->readSmtpMsg());
            return false;
        }

        return true;
    }

    /*
    关闭邮件连接
    */
    public function close() {

        $this->sendSmtpMsg('Quite');
        @socket_close(self::$smtpCon);
    }

    /*
    添加普通邮件头信息
    */
    protected function mailHeader($to, $title) {
        $headers   = array();
        $headers[] = 'Date: ' . $this->gmtime('D j M Y H:i:s') . ' ' . date('O');

        if (!is_array($to)) {
            $headers[] = 'To: "' . '=?' . $this->charset . '?B?' . base64_encode($this->getMailUser($to)) . '?="<' . $to . '>';
        } else {
            foreach ($to as $k => $e) {
                $headers[] = 'To: "' . '=?' . $this->charset . '?B?' . base64_encode($this->getMailUser($e)) . '?="<' . $e . '>';
            }
        }

        $headers[] = 'From: "=?' . $this->charset . '?B?' . base64_encode($this->senderName) . '?="<' . $this->senderMail . '>';
        $headers[] = 'Subject: =?' . $this->charset . '?B?' . base64_encode($title) . '?=';
        $headers[] = 'Content-type: text/html; charset=' . $this->charset . '; format=flowed';
        $headers[] = 'Content-Transfer-Encoding: base64';

        $headers = str_replace($this->stop . '.', $this->stop . '..', trim(implode($this->stop, $headers)));
        return $headers;
    }

    /*
    带付件的头部信息
    */
    protected function mimeHeader($msg, $to, $title) {

        if ($this->attachMent != null) {

            $headers   = array();
            $boundary  = '----=' . uniqid();
            $headers[] = 'Date: ' . $this->gmtime('D j M Y H:i:s') . ' ' . date('O');
            if (!is_array($to)) {
                $headers[] = 'To: "' . '=?' . $this->charset . '?B?' . base64_encode($this->getMailUser($to)) . '?="<' . $to . '>';
            } else {
                foreach ($to as $k => $e) {
                    $headers[] = 'To: "' . '=?' . $this->charset . '?B?' . base64_encode($this->getMailUser($e)) . '?="<' . $e . '>';
                }
            }

            $headers[] = 'From: "=?' . $this->charset . '?B?' . base64_encode($this->senderName) . '?="<' . $this->senderMail . '>';
            $headers[] = 'Subject: =?' . $this->charset . '?B?' . base64_encode($title) . '?=';
            $headers[] = 'Mime-Version: 1.0';
            $headers[] = 'Content-Type: multipart/mixed;boundary="' . $boundary . '"' . $this->stop;
            $headers[] = '--' . $boundary;

            $headers[] = 'Content-Type: text/html;charset="' . $this->charset . '"';
            $headers[] = 'Content-Transfer-Encoding: base64' . $this->stop;
            $headers[] = '';
            $headers[] = $msg . $this->stop;

            foreach ($this->attachMent as $k => $filename) {

                $f        = @fopen($filename, 'r');
                $mimetype = $this->getMimeType(realpath($filename));
                $mimetype = $mimetype == '' ? 'application/octet-stream' : $mimetype;

                $attachment = @fread($f, filesize($filename));
                $attachment = base64_encode($attachment);
                $attachment = chunk_split($attachment);

                $headers[] = "--" . $boundary;
                $headers[] = "Content-type: " . $mimetype . ";name=\"=?" . $this->charset . "?B?" . base64_encode(basename($filename)) . '?="';
                $headers[] = "Content-disposition: attachment; name=\"=?" . $this->charset . "?B?" . base64_encode(basename($filename)) . '?="';
                $headers[] = 'Content-Transfer-Encoding: base64' . $this->stop;
                $headers[] = $attachment . $this->stop;


            }
            $headers[] = "--" . $boundary . "--";
            $headers   = str_replace($this->stop . '.', $this->stop . '..', trim(implode($this->stop, $headers)));
            return $headers;

        }
    }

    /*
    获取返回状态
    */
    protected function getStatus() {

        $this->status = substr($this->readSmtpMsg(), 0, 3);
    }


    /*
    获取邮件服务器返回的信息
    @return string 信息字符串
    */
    protected function readSmtpMsg() {

        if (!is_resource(self::$smtpCon)) {
            return false;
        }

        $return = '';
        $line   = '';
        while (strpos($return, $this->stop) === false OR $line{3} !== ' ') {
            $line = fgets(self::$smtpCon, 512);
            $return .= $line;
        }

        return trim($return);

    }

    /*
    给邮件服务器发给指定命令消息
    */
    protected function sendSmtpMsg($cmd, $chStatus = true) {
        if (is_resource(self::$smtpCon)) {
            fwrite(self::$smtpCon, $cmd . $this->stop, strlen($cmd) + 2);
        }
        if ($chStatus == true) {
            $this->getStatus();
        }

        return true;
    }

    /*
    邮件时间格式
    */
    protected function gmtime($format) {

        return date($format,(time() - date('Z')));

    }

    /*
    获取付件的mime类型
    */
    protected function getMimeType($file) {

        $mimes = array(
            'chm'  => 'application/octet-stream', 'ppt' => 'application/vnd.ms-powerpoint',
            'xls'  => 'application/vnd.ms-excel', 'doc' => 'application/msword', 'exe' => 'application/octet-stream',
            'rar'  => 'application/octet-stream', 'js' => "javascrīpt/js", 'css' => "text/css",
            'hqx'  => "application/mac-binhex40", 'bin' => "application/octet-stream", 'oda' => "application/oda", 'pdf' => "application/pdf",
            'ai'   => "application/postsrcipt", 'eps' => "application/postsrcipt", 'es' => "application/postsrcipt", 'rtf' => "application/rtf",
            'mif'  => "application/x-mif", 'csh' => "application/x-csh", 'dvi' => "application/x-dvi", 'hdf' => "application/x-hdf",
            'nc'   => "application/x-netcdf", 'cdf' => "application/x-netcdf", 'latex' => "application/x-latex", 'ts' => "application/x-troll-ts",
            'src'  => "application/x-wais-source", 'zip' => "application/zip", 'bcpio' => "application/x-bcpio", 'cpio' => "application/x-cpio",
            'gtar' => "application/x-gtar", 'shar' => "application/x-shar", 'sv4cpio' => "application/x-sv4cpio", 'sv4crc' => "application/x-sv4crc",
            'tar'  => "application/x-tar", 'ustar' => "application/x-ustar", 'man' => "application/x-troff-man", 'sh' => "application/x-sh",
            'tcl'  => "application/x-tcl", 'tex' => "application/x-tex", 'texi' => "application/x-texinfo", 'texinfo' => "application/x-texinfo",
            't'    => "application/x-troff", 'tr' => "application/x-troff", 'roff' => "application/x-troff", 'me' => "application/x-troll-me",
            'gif'  => "image/gif", 'jpeg' => "image/pjpeg", 'jpg' => "image/pjpeg", 'jpe' => "image/pjpeg", 'ras' => "image/x-cmu-raster",
            'pbm'  => "image/x-portable-bitmap", 'ppm' => "image/x-portable-pixmap", 'xbm' => "image/x-xbitmap", 'xwd' => "image/x-xwindowdump",
            'ief'  => "image/ief", 'tif' => "image/tiff", 'tiff' => "image/tiff", 'pnm' => "image/x-portable-anymap", 'pgm' => "image/x-portable-graymap",
            'rgb'  => "image/x-rgb", 'xpm' => "image/x-xpixmap", 'txt' => "text/plain", 'c' => "text/plain", 'cc' => "text/plain",
            'h'    => "text/plain", 'html' => "text/html", 'htm' => "text/html", 'htl' => "text/html", 'rtx' => "text/richtext", 'etx' => "text/x-setext",
            'tsv'  => "text/tab-separated-values", 'mpeg' => "video/mpeg", 'mpg' => "video/mpeg", 'mpe' => "video/mpeg", 'avi' => "video/x-msvideo",
            'qt'   => "video/quicktime", 'mov' => "video/quicktime", 'moov' => "video/quicktime", 'movie' => "video/x-sgi-movie", 'au' => "audio/basic",
            'snd'  => "audio/basic", 'wav' => "audio/x-wav", 'aif' => "audio/x-aiff", 'aiff' => "audio/x-aiff", 'aifc' => "audio/x-aiff",
            'swf'  => "application/x-shockwave-flash", 'myz' => "application/myz"
        );

        $ext  = substr(strrchr($file, '.'), 1);
        $type = $mimes[$ext];


        unset($mimes);
        return $type;
    }

    /*
    邮件helo命令
    */
    private function helo() {

        if ($this->status != '220') {

            $this->error('连接服务器失败!');
            return false;
        }

        return $this->sendSmtpMsg('HELO ' . $this->server);

    }


    /*
    登录
    */
    private function login() {

        if ($this->status != '250') {

            $this->error('helo邮件指令失败!');
            return false;
        }

        $this->sendSmtpMsg('AUTH LOGIN');
        if ($this->status != '334') {
            $this->error('AUTH LOGIN 邮件指令失败!');
            return false;
        }

        $this->sendSmtpMsg(base64_encode($this->mailUser));
        if ($this->status != '334') {
            $this->error('邮件登录用户名可能不正确!' . $this->readSmtpMsg());
            return false;
        }

        $this->sendSmtpMsg(base64_encode($this->mailPwd));
        if ($this->status != '235') {
            $this->error('邮件登录密码可能不正确!');
            return false;
        }

        return true;

    }

    private function getMailUser($to) {

        $temp = explode('@', $to);
        return $temp[0];
    }

    /*
    异常报告
    */
    private function error($exception) {
        throw new \Exception($exception);
    }

}