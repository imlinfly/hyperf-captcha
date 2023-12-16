<?php
/**
 * @desc Captcha.php 描述信息
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/3/24 21:36
 */
declare(strict_types=1);

namespace Lynnfly\HyperfCaptcha;

use Exception;
use GdImage;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use RedisException;
use function Hyperf\Config\config;

class Captcha
{
    /**
     * 验证验证码
     *
     * @param string $code 验证码
     * @param string $key 验证码key
     * @return bool
     *
     * @noinspection PhpUnhandledExceptionInspection
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function check(string $code, string $key): bool
    {
        $config = self::getConfig();

        $cacheKey = $config['prefix'] . $key;

        $redis = self::getRedis();

        if (!$redis->exists($cacheKey)) {
            return false;
        }

        $hash = $redis->hGet($cacheKey, 'key');
        $code = mb_strtolower($code, 'UTF-8');
        $res = password_verify($code, $hash);

        // 不管验证成功与否都删除key 防止被暴力破解
        $redis->del($cacheKey);

        return $res;
    }

    /**
     * 获取验证码并缓存验证码信息
     *
     * @param array $options
     * @return array{
     *     key: string,
     *     base64: string,
     * }
     *
     * @noinspection PhpUnhandledExceptionInspection
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function create(array $options = [])
    {
        $config = self::getConfig();

        if (!empty($options)) {
            $config = array_merge($config, $options);
        }

        $generator = self::generate($config);
        // 图片宽(px)
        $config['imageW'] || $config['imageW'] = $config['length'] * $config['fontSize'] * 1.5 + $config['length'] * $config['fontSize'] / 2;
        // 图片高(px)
        $config['imageH'] || $config['imageH'] = $config['fontSize'] * 2.5;
        // 建立一幅 $config['imageW'] x $config['imageH'] 的图像
        $im = imagecreate((int)$config['imageW'], (int)$config['imageH']);
        // 设置背景
        imagecolorallocate($im, $config['bg'][0], $config['bg'][1], $config['bg'][2]);

        // 验证码字体随机颜色
        $color = imagecolorallocate($im, random_int(1, 150), random_int(1, 150), random_int(1, 150));

        // 验证码使用随机字体
        $ttfPath = __DIR__ . '/../assets/' . ($config['useZh'] ? 'zhttfs' : 'ttfs') . '/';

        if (empty($config['fontttf'])) {
            $dir = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (str_ends_with($file, '.ttf') || str_ends_with($file, '.otf')) {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $config['fontttf'] = $ttfs[array_rand($ttfs)];
        }

        $fontttf = $ttfPath . $config['fontttf'];

        if ($config['useImgBg']) {
            self::background($config, $im);
        }

        if ($config['useNoise']) {
            // 绘杂点
            self::writeNoise($config, $im);
        }
        if ($config['useCurve']) {
            // 绘干扰线
            self::writeCurve($config, $im, $color);
        }

        // 绘验证码
        $text = $config['useZh'] ? preg_split('/(?<!^)(?!$)/u', $generator['value']) : str_split($generator['value']); // 验证码

        foreach ($text as $index => $char) {
            $x = $config['fontSize'] * ($index + 1) * ($config['math'] ? 1 : 1.5);
            $y = $config['fontSize'] + random_int(10, 20);
            $angle = $config['math'] ? 0 : random_int(-40, 40);
            imagettftext($im, $config['fontSize'], $angle, (int)$x, (int)$y, $color, $fontttf, $char);
        }

        ob_start();
        imagepng($im);
        $content = ob_get_clean();
        imagedestroy($im);

        return [
            'key' => $generator['key'],
            'base64' => 'data:image/png;base64,' . base64_encode($content),
        ];
    }

    /**
     * 生成验证码
     *
     * @param array $config
     * @return array
     *
     * @noinspection PhpUnhandledExceptionInspection
     * @noinspection PhpDocMissingThrowsInspection
     */
    protected static function generate(array $config): array
    {
        $bag = '';
        if ($config['math']) {
            $config['useZh'] = false;
            $config['length'] = 5;
            $x = random_int(10, 30);
            $y = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            if ($config['useZh']) {
                $characters = preg_split('/(?<!^)(?!$)/u', $config['zhSet']);
            } else {
                $characters = str_split($config['codeSet']);
            }

            for ($i = 0; $i < $config['length']; $i++) {
                $bag .= $characters[rand(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }

        $config = self::getConfig();
        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);

        $redis = self::getRedis();

        try {
            $redis->multi();
            $redis->hMSet($config['prefix'] . $hash, ['key' => $hash]);
            $redis->expire($config['prefix'] . $hash, $config['expire'] ?? 60);
            $redis->exec();
        } catch (RedisException $e) {
            $redis->discard();
            throw $e;
        }

        return ['value' => $bag, 'key' => $hash];
    }

    /**
     * 画一条由两条连在一起构成的随机正弦函数曲线作干扰线(你可以改成更帅的曲线函数)
     *
     * @param array $config
     * @param GdImage $im
     * @param int $color
     * @throws Exception
     */
    protected static function writeCurve(array $config, GdImage $im, int $color): void
    {
        $py = 0;
        // 曲线前部分
        $A = random_int(1, (int)($config['imageH'] / 2)); // 振幅
        $b = random_int(-(int)($config['imageH'] / 4), (int)($config['imageH'] / 4)); // Y轴方向偏移量
        $f = random_int(-(int)($config['imageH'] / 4), (int)($config['imageH'] / 4)); // X轴方向偏移量
        $T = random_int((int)$config['imageH'], (int)($config['imageW'] * 2)); // 周期
        $w = (2 * M_PI) / $T;

        $px1 = 0; // 曲线横坐标起始位置
        $px2 = random_int((int)($config['imageW'] / 2), (int)$config['imageW']); // 曲线横坐标结束位置

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $config['imageH'] / 2; // y = Asin(ωx+φ) + b
                $i = (int)($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int)$px + $i, (int)$py + $i, (int)$color); // 这里(while)循环画像素点比imagettftext和imagestring用字体大小一次画出（不用这while循环）性能要好很多
                    $i--;
                }
            }
        }

        // 曲线后部分
        $A = random_int(1, (int)($config['imageH'] / 2)); // 振幅
        $f = random_int(-(int)($config['imageH'] / 4), (int)($config['imageH'] / 4)); // X轴方向偏移量
        $T = random_int((int)$config['imageH'], (int)($config['imageW'] * 2)); // 周期
        $w = (2 * M_PI) / $T;
        $b = $py - $A * sin($w * $px + $f) - $config['imageH'] / 2;
        $px1 = $px2;
        $px2 = $config['imageW'];

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $config['imageH'] / 2; // y = Asin(ωx+φ) + b
                $i = (int)($config['fontSize'] / 5);
                while ($i > 0) {
                    imagesetpixel($im, (int)$px + $i, (int)$py + $i, (int)$color);
                    $i--;
                }
            }
        }
    }

    /**
     * 画杂点  往图片上写不同颜色的字母或数字
     *
     * @param array $config
     * @param GdImage $im
     *
     * @noinspection PhpUnhandledExceptionInspection
     * @noinspection PhpDocMissingThrowsInspection
     */
    protected static function writeNoise(array $config, GdImage $im): void
    {
        $codeSet = '20222345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; $i++) {
            //杂点颜色
            $noiseColor = imagecolorallocate($im, random_int(150, 225), random_int(150, 225), random_int(150, 225));
            for ($j = 0; $j < 5; $j++) {
                // 绘杂点
                imagestring($im, 5, random_int(-10, (int)$config['imageW']), random_int(-10, (int)$config['imageH']), $codeSet[random_int(0, 29)], (int)$noiseColor);
            }
        }
    }

    /**
     * 绘制背景图片 注：如果验证码输出图片比较大，将占用比较多的系统资源
     *
     * @param array $config
     * @param GdImage $im
     */
    protected static function background(array $config, GdImage $im): void
    {
        $path = __DIR__ . '/../assets/bgs/';
        $dir = dir($path);

        $bgs = [];
        while (false !== ($file = $dir->read())) {
            if ('.' != $file[0] && str_ends_with($file, '.jpg')) {
                $bgs[] = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];

        [$width, $height] = @getimagesize($gb);
        $bgImage = @imagecreatefromjpeg($gb);
        @imagecopyresampled($im, $bgImage, 0, 0, 0, 0, (int)$config['imageW'], (int)$config['imageH'], $width, $height);
        @imagedestroy($bgImage);
    }

    /**
     * @noinspection PhpUnhandledExceptionInspection
     */
    public static function getRedis(): RedisProxy
    {
        $pool = self::getConfig()['redis_pool'] ?? 'default';

        return ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get($pool);
    }

    protected static function getConfig(): array
    {
        return config('captcha', [
            // Redis连接
            'redis_pool' => 'default',
            // 验证码存储key前缀
            'prefix' => 'captcha',
            // 验证码字符集合
            'codeSet' => 'ABCDEFGHJKLMNPQRTUVWXY2345678abcdefhijkmnpqrstuvwxyz',
            // 是否使用中文验证码
            'useZh' => false,
            // 中文验证码字符串
            'zhSet' => '以最小内核提供最大的扩展性与最强的性能们以我到他会作时要动国产的一是工就年阶义发成部民可出能方进在了不和有大这主中人上为来分生对于学下级地个用同行面说种过命度革而多子后自社加小机也经力线本电高量长党得实家定深法表着水理化争现所二起政三好十战无农使性前等反体合斗路图把结第里正新开论之物从当两些还天资事队批点育重其思与间内去因件日利相由压员气业代全组数果期导平各基或月毛然如应形想制心样干都向变关问比展那它最及外没看治提五解系林者米群头意只明四道马认次文通但条较克又公孔领军流入接席位情运器并飞原油放立题质指建区验活众很教决特此常石强极土少已根共直团统式转别造切九你取西持总料连任志观调七么山程百报更见必真保热委手改管处己将修支识病象几先老光专什六型具示复安带每东增则完风回南广劳轮科北打积车计给节做务被整联步类集号列温装即毫知轴研单色坚据速防史拉世设达尔场织历花受求传口断况采精金界品判参层止边清至万确究书术状厂须离再目海交权且儿青才证低越际八试规斯近注办布门铁需走议县兵固除般引齿千胜细影济白格效置推空配刀叶率述今选养德话查差半敌始片施响收华觉备名红续均药标记难存测士身紧液派准斤角降维板许破述技消底床田势端感往神便贺村构照容非搞亚磨族火段算适讲按值美态黄易彪服早班麦削信排台声该击素张密害侯草何树肥继右属市严径螺检左页抗苏显苦英快称坏移约巴材省黑武培著河帝仅针怎植京助升王眼她抓含苗副杂普谈围食射源例致酸旧却充足短划剂宣环落首尺波承粉践府鱼随考刻靠够满夫失包住促枝局菌杆周护岩师举曲春元超负砂封换太模贫减阳扬江析亩木言球朝医校古呢稻宋听唯输滑站另卫字鼓刚写刘微略范供阿块某功套友限项余倒卷创律雨让骨远帮初皮播优占死毒圈伟季训控激找叫云互跟裂粮粒母练塞钢顶策双留误础吸阻故寸盾晚丝女散焊功株亲院冷彻弹错散商视艺灭版烈零室轻血倍缺厘泵察绝富城冲喷壤简否柱李望盘磁雄似困巩益洲脱投送奴侧润盖挥距触星松送获兴独官混纪依未突架宽冬章湿偏纹吃执阀矿寨责熟稳夺硬价努翻奇甲预职评读背协损棉侵灰虽矛厚罗泥辟告卵箱掌氧恩爱停曾溶营终纲孟钱待尽俄缩沙退陈讨奋械载胞幼哪剥迫旋征槽倒握担仍呀鲜吧卡粗介钻逐弱脚怕盐末阴丰雾冠丙街莱贝辐肠付吉渗瑞惊顿挤秒悬姆烂森糖圣凹陶词迟蚕亿矩康遵牧遭幅园腔订香肉弟屋敏恢忘编印蜂急拿扩伤飞露核缘游振操央伍域甚迅辉异序免纸夜乡久隶缸夹念兰映沟乙吗儒杀汽磷艰晶插埃燃欢铁补咱芽永瓦倾阵碳演威附牙芽永瓦斜灌欧献顺猪洋腐请透司危括脉宜笑若尾束壮暴企菜穗楚汉愈绿拖牛份染既秋遍锻玉夏疗尖殖井费州访吹荣铜沿替滚客召旱悟刺脑措贯藏敢令隙炉壳硫煤迎铸粘探临薄旬善福纵择礼愿伏残雷延烟句纯渐耕跑泽慢栽鲁赤繁境潮横掉锥希池败船假亮谓托伙哲怀割摆贡呈劲财仪沉炼麻罪祖息车穿货销齐鼠抽画饲龙库守筑房歌寒喜哥洗蚀废纳腹乎录镜妇恶脂庄擦险赞钟摇典柄辩竹谷卖乱虚桥奥伯赶垂途额壁网截野遗静谋弄挂课镇妄盛耐援扎虑键归符庆聚绕摩忙舞遇索顾胶羊湖钉仁音迹碎伸灯避泛亡答勇频皇柳哈揭甘诺概宪浓岛袭谁洪谢炮浇斑讯懂灵蛋闭孩释乳巨徒私银伊景坦累匀霉杜乐勒隔弯绩招绍胡呼痛峰零柴簧午跳居尚丁秦稍追梁折耗碱殊岗挖氏刃剧堆赫荷胸衡勤膜篇登驻案刊秧缓凸役剪川雪链渔啦脸户洛孢勃盟买杨宗焦赛旗滤硅炭股坐蒸凝竟陷枪黎救冒暗洞犯筒您宋弧爆谬涂味津臂障褐陆啊健尊豆拔莫抵桑坡缝警挑污冰柬嘴啥饭塑寄赵喊垫丹渡耳刨虎笔稀昆浪萨茶滴浅拥穴覆伦娘吨浸袖珠雌妈紫戏塔锤震岁貌洁剖牢锋疑霸闪埔猛诉刷狠忽灾闹乔唐漏闻沈熔氯荒茎男凡抢像浆旁玻亦忠唱蒙予纷捕锁尤乘乌智淡允叛畜俘摸锈扫毕璃宝芯爷',
            // 是否使用背景图（不建议开启）
            'useImgBg' => false,
            // 是否使用混淆曲线
            'useCurve' => false,
            // 是否添加杂点
            'useNoise' => false,
            // 验证码图片高度
            'imageH' => 0,
            // 验证码图片宽度
            'imageW' => 0,
            // 验证码位数
            'length' => 5,
            // 验证码字符大小
            'fontSize' => 25,
            // 验证码过期时间 不设置默认60秒
            'expire' => 1800,
            // 验证码字体 不设置则随机
            'fontttf' => '',
            // 背景颜色
            'bg' => [243, 251, 254],
            // 是否使用算术验证码（不建议开启）
            'math' => false,
        ]);
    }
}
