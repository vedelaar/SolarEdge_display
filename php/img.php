<?php
// https://knowledge-center.solaredge.com/sites/kc/files/se_monitoring_api.pdf

class curlclass
{
    private $lasturl;
    private $curl;

    function __CONSTRUCT($header=true)
    {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HEADER, $header);
        curl_setopt($this->curl, CURLOPT_PROXY, @$_SERVER["http_proxy"]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; .NET CLR 3.0.04506; InfoPath.2)");
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, 1);
    }

    function getpage($page)
    {
        curl_setopt($this->curl, CURLOPT_URL, $page);
        $data = curl_exec($this->curl);
        return $data;
    }

    function postpage($page,$postfields)
    {
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postfields);
        $data=$this->getpage($page);
        curl_setopt($this->curl, CURLOPT_POST, false);
        return $data;
    }
}

class SolarEdge
{
  public $curl;
  public $siteId;
  public $apiKey;

  public function __construct($siteId, $apiKey) {
    $this->curl = new curlclass(false);
    $this->siteId = $siteId;
    $this->apiKey = $apiKey;
  }

  // example: site/{siteid}/inventory
  public function get($path) {
    $path = str_replace('{siteId}', $this->siteId, $path);
    $url = 'https://monitoringapi.solaredge.com/'.$path;
    if(strpos($path, '?') === false)
      $url .= "?";
    else
      $url .= '&';
    $url.="api_key=".$this->apiKey;
    $url.='&version=1.0.0';
    //echo $url."\n";
    $data = $this->curl->getpage($url);
    //var_dump($data);
    return json_decode($data,true);
  }

  public function getInventory() {
    return $this->get('site/{siteId}/inventory');
  }

  public function getInverterFromInventory($inventory,$num) {
    return $inventory['Inventory']['inverters'][$num] ?? null;
  }

  public function getSiteDetails() {
    return $this->get('site/{siteId}/details');
  }

  // Current production, production today, production this month, production this year, production lifetime
  public function getSiteOverview() {
    return $this->get('site/{siteId}/overview');
  }

  public function getSiteDataPeriod() {
    return $this->get('site/{siteId}/dataPeriod');
  }

  // timeunit = QUARTER_OF_AN_HOUR | HOUR | DAY | WEEK | MONTH | YEAR
  // date = yyyy-mm-dd
  public function getSiteEnergy($startDate, $endDate, $timeUnit) {
    return $this->get('site/{siteId}/energy?timeUnit='.$timeUnit.'&startDate='.$startDate.'&endDate='.$endDate);
  }

  public function getSitePowerDetails($startTime, $endTime) {
    return $this->get('site/{siteId}/powerDetails?startTime='.urlencode($startTime).'&endTime='.urlencode($endTime));
  }

  public function getInverterTechnicalData($inverter, $startTime, $endTime) {
    return $this->get('equipment/{siteId}/'.$inverter['SN'].'/data?startTime='.urlencode($startTime).'&endTime='.urlencode($endTime));
  }

}

//echo date("Y-m-d H:i:s");

// $x = new SolarEdge('','');
// $o = $x->getSiteOverview();
// //$sdp = $x->getSiteDataPeriod();
// $from = date("Y-m-d", strtotime("yesterday"));
// $to = date("Y-m-d");
// $se = $x->getSiteEnergy($from, $to, 'DAY');
// //print_r($se);
// //$se = $x->getSitePowerDetails('2022-12-26 11:11:11', '2022-12-27 12:12:12');
// //$inventory = $x->getInventory();
// //$inverter = $x->getInverterFromInventory($inventory, 0);
// //$se = $x->getInverterTechnicalData($inverter, '2022-12-26 11:11:11', '2022-12-27 11:11:11');
// print_r($se);
// //$x->getEnergyForGraph();



class Display
{
  public $solarEdge;
  public $gd;
  public $black;
  public $white;
  public $gray;
  public $font = "./LiberationSans-Bold.ttf";

  public $canvas_width = 960;
  public $canvas_height = 540;

  public function __construct($solarEdge, $w = 960, $h = 540) {
    $this->solarEdge = $solarEdge;
    $this->canvas_width = $w;
    $this->canvas_height = $h;
    $this->gd = @imagecreate($this->canvas_width, $this->canvas_height)
        or die("Cannot Initialize new GD image stream");
    imageantialias($this->gd, true);
    $this->black = imagecolorallocate($this->gd, 0, 0, 0);
    $this->white = imagecolorallocate($this->gd, 255, 255, 255);
    $this->gray = imagecolorallocate($this->gd, 148,148,148);
  }

  public function drawBar($bot_x, $bot_y, $width, $color, $color2, $height) {

      imagefilledarc($this->gd, $bot_x, $bot_y, $width, $width/2,  0, 180, $color, IMG_ARC_PIE);
      imagefilledrectangle($this->gd, $bot_x-($width/2), $bot_y, $bot_x+($width/2), $bot_y-$height, $color);
      imagefilledarc($this->gd, $bot_x, $bot_y-$height, $width, $width/2,  180, 0, $color, IMG_ARC_PIE);

  }

  public function calcHighest($data) {
    $max = 0;
    foreach($data as $val) {
      if ($val > $max)
        $max = $val;
    }
    return $max;
  }


  public function drawBars($dataa) {
    $data = [];
    foreach($dataa as $dat){ $data[] = $dat['value'] | 0; }
    $y = 430;
    //$avg = $this->getAvg();
    $max = $this->calcHighest($data);
    $im = &$this->gd;
    $white = &$this->white;
    $gray = &$this->gray;

    for($i=0;$i<8;$i++) {
      $barmult = 3.9;
      $barw = 46;
      $x = $i*(20+$barw) + 30;
      $num = (($data[$i] * 100)/$max);
      $this->drawBar($x+$barw, $y, $barw, $this->white, $this->gray, round($num*$barmult));
      $this->putAmountLabels($x+$barw+2, $y+30, $this->white, $data[$i]/1000, 28,320);
    }
  }

  public function putAmountLabels($x, $y, $color, $amount, $size = 14, $angle = 315) {
    imagettftext($this->gd, $size, $angle, $x, $y, $color, $this->font, round($amount,2));
  }

  public function draw() {
    //$from = date("Y-m-d", strtotime("7 days ago"));
    $from = date("Y-m-d", strtotime("1 year ago + 1 day")); // 10-1-2022 tot en met 09-1-2022
    $to = date("Y-m-d");
    $se = $this->solarEdge->getSiteEnergy($from, $to, 'DAY');
    $vals = $se['energy']['values'];

    // BARS
    $vals_bak = $vals;
    $this->drawBars(array_splice($vals_bak, -8));

    // TODAY
    //imagettftext($this->gd, 20, 0, 650, 50, $this->white, $this->font, 'Today:');
    imagettftext($this->gd, 25, 0, 650, 60, $this->white, $this->font, 'Vandaag:');
    $text = round($vals[count($vals)-1]['value']/1000,2);
    //if ($text > 10)
    //  $text = round($text, 2);
    imagettftext($this->gd, 80, 0, 650, 150, $this->white, $this->font, $text);
    imagettftext($this->gd, 40, 0, 780, 200, $this->white, $this->font, "kWh");

    // LAST UPDATE
    $sov = $this->solarEdge->getSiteOverview();
    imagettftext($this->gd, 15, 0, 650, $this->canvas_height-60, $this->white, $this->font, 'laatst geüpdatet:');
    //imagettftext($this->gd, 20, 0, 650, $this->canvas_height-60, $this->white, $this->font, 'Last updated on:');
    imagettftext($this->gd, 22, 0, 650, $this->canvas_height-30, $this->white, $this->font, $sov['overview']['lastUpdateTime']);

    // THIS YEAR
    $text = round($sov['overview']['lastYearData']['energy']/1000,2);
    imagettftext($this->gd, 15, 0, 650, 250, $this->white, $this->font, "Dit kalenderjaar:");
    imagettftext($this->gd, 30, 0, 650, 285, $this->white, $this->font, $text);
    $box1 = $this->calculateTextBox($text, $this->font, 30,0);

    // LAST 365 DAYS
    $last365days = array_reduce($se['energy']['values'], function($c, $i){ return $c + $i['value']; });
    $text = round($last365days/1000,2);
    imagettftext($this->gd, 15, 0, 650, 320, $this->white, $this->font, "Afgelopen 365 dagen:");
    imagettftext($this->gd, 30, 0, 650, 355, $this->white, $this->font, $text);
    $box2 = $this->calculateTextBox($text, $this->font, 30,0);

    // THIS CONTRACT YEAR
    $contractdate = strtotime("aug 13 00:00:00");
    if ($contractdate > time())
      $contractdate = strtotime('-1 year', $contractdate);

    $thiscontract = array_reduce($se['energy']['values'], function($c, $i) use ($contractdate){
        if (strtotime($i['date']) >= $contractdate) {
          return $c + $i['value'];
        }
        return $c;
      });

    $text = round($thiscontract/1000,2);
    imagettftext($this->gd, 15, 0, 650, 390, $this->white, $this->font, "Dit contractjaar:");
    imagettftext($this->gd, 30, 0, 650, 425, $this->white, $this->font, $text);
    $box3 = $this->calculateTextBox($text, $this->font, 30,0);

    // KWH LABELS
    $maxbox = max($box1['width'], $box2['width'], $box3['width']);
    imagettftext($this->gd, 15, 0, 650 + $maxbox + 10, 275, $this->white, $this->font, "kWh");
    imagettftext($this->gd, 15, 0, 650 + $maxbox + 10, 345, $this->white, $this->font, "kWh");
    imagettftext($this->gd, 15, 0, 650 + $maxbox + 10, 415, $this->white, $this->font, "kWh");
  }

  public function imagettftextcentered($gd, $size, $angle, $x_center, $y_center, $color, $font, $text, $options = []){
    $arr = $this->calculateTextBox($text, $this->font, 80, 0);
    $x = (-$arr['left'])-($arr['width']/2);
    $y = ($arr['top'])-($arr['height']/2);
    imagettftext($gd, $size, $angle, $x+$x_center, $y+$y_center, $color, $font, $text);
  }

  // https://www.php.net/manual/en/function.imagettfbbox.php
  public function calculateTextBox($text,$fontFile,$fontSize,$fontAngle) {
      /************
      simple function that calculates the *exact* bounding box (single pixel precision).
      The function returns an associative array with these keys:
      left, top:  coordinates you will pass to imagettftext
      width, height: dimension of the image you have to create
      *************/
      $rect = imagettfbbox($fontSize,$fontAngle,$fontFile,$text);
      $minX = min(array($rect[0],$rect[2],$rect[4],$rect[6]));
      $maxX = max(array($rect[0],$rect[2],$rect[4],$rect[6]));
      $minY = min(array($rect[1],$rect[3],$rect[5],$rect[7]));
      $maxY = max(array($rect[1],$rect[3],$rect[5],$rect[7]));

      return array(
       "left"   => abs($minX) - 1,
       "top"    => abs($minY) - 1,
       "width"  => $maxX - $minX,
       "height" => $maxY - $minY,
       "box"    => $rect
      );
  }

  public function toCppData() {
    for($y=0; $y<$this->canvas_height; $y++) {
      $byte = 0;
      $done = true;
      for($x=0; $x<$this->canvas_width; $x++) {
        $pix = imagecolorat($this->gd, $x, $y);
        $colors = imagecolorsforindex($this->gd, $pix);
        $col = (($colors["red"] + $colors["green"] + $colors["blue"]) / 3) >> 4;
        //print_r($colors);
        //echo "$col\n";
        if ($x%2 == 0) {
          $byte = $col;
          $done = false;
        } else {
          $byte |= ($col << 4);
          echo pack("C",$byte);
          //echo "0x".bin2hex(pack("C",$byte)).", ";
          $done = true;
        }
      }
      if (!$done)
          echo pack("C",$byte);
          //echo "0x".bin2hex(pack("C",$byte)).", ";
      //echo "\n";
    }
  }

  public function toImage() {
    imagepng($this->gd, "img.png");
    imagedestroy($this->gd);
  }
}


$se = new SolarEdge('','');
$display = new Display($se);
$display->draw();

ob_start();
$display->toCppData();
header("Content-length: ".ob_get_length());
ob_end_flush();

$display->toImage();


