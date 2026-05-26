<?php
/**
 * 批量生成 GEO 区域采购页面
 *  - 33 个省级行政区（22 省 + 5 自治区 + 4 直辖市 + 2 特区，含台湾）
 *  - 已存在的 slug 默认跳过（--force 才覆盖）
 *  - 模板：复用 topics/geo 现有 css class，FAQ 用 h3+p 直出（无折叠）
 *
 * 用法：
 *   php scripts/build_geo_pages.php --concurrency=12 --limit=50
 *   php scripts/build_geo_pages.php --only=jiangsu --force
 *   php scripts/build_geo_pages.php --dry-run --limit=2
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/llm_prompt_kit.php';

class GeoPageBuilder
{
    private const OUT_DIR = '/topics/geo';
    private const LOG_FILE = '/runtime/build_geo_pages.log';

    /** 33 省级行政区: slug => [name, region, focus_industries[5], hub_cities[3-5], chip_keywords[]] */
    private const PROVINCES = [
        // 直辖市
        'beijing-province' => ['name' => '北京市', 'region' => '华北', 'industries' => ['工业自动化', '科研仪器', '智能装备', '电子信息', '环保设备'], 'cities' => ['北京', '通州', '顺义', '亦庄', '昌平']],
        'shanghai-province' => ['name' => '上海市', 'region' => '华东', 'industries' => ['汽车制造', '工业自动化', '生物医药', '集成电路', '工业涂料'], 'cities' => ['上海', '浦东', '嘉定', '松江', '青浦']],
        'tianjin-province' => ['name' => '天津市', 'region' => '华北', 'industries' => ['航空装备', '化工材料', '海洋工程', '汽车零部件', '钢铁加工'], 'cities' => ['天津', '滨海新区', '武清', '北辰', '津南']],
        'chongqing-province' => ['name' => '重庆市', 'region' => '西南', 'industries' => ['汽车摩托车', '电子制造', '装备制造', '材料加工', '页岩气化工'], 'cities' => ['重庆', '两江新区', '永川', '涪陵', '万州']],
        // 华东省份
        'jiangsu' => ['name' => '江苏省', 'region' => '华东', 'industries' => ['工业自动化', '电子制造', '机械装备', '纺织化工', '光伏新能源'], 'cities' => ['苏州', '南京', '无锡', '常州', '南通']],
        'zhejiang' => ['name' => '浙江省', 'region' => '华东', 'industries' => ['五金机电', '纺织印染', '化纤化工', '轻工日化', '电气电子'], 'cities' => ['杭州', '宁波', '温州', '嘉兴', '绍兴']],
        'anhui' => ['name' => '安徽省', 'region' => '华东', 'industries' => ['汽车装备', '家电制造', '钢铁建材', '煤化工', '电子信息'], 'cities' => ['合肥', '芜湖', '马鞍山', '蚌埠', '安庆']],
        'fujian' => ['name' => '福建省', 'region' => '华东', 'industries' => ['鞋服纺织', '电子信息', '机械装备', '建材石材', '海洋食品'], 'cities' => ['厦门', '福州', '泉州', '漳州', '莆田']],
        'jiangxi' => ['name' => '江西省', 'region' => '华东', 'industries' => ['有色金属', '电子信息', '航空装备', '中医药', '陶瓷新材'], 'cities' => ['南昌', '赣州', '九江', '宜春', '上饶']],
        'shandong' => ['name' => '山东省', 'region' => '华东', 'industries' => ['机械重工', '化工炼化', '橡胶轮胎', '海洋装备', '农业食品'], 'cities' => ['青岛', '济南', '烟台', '潍坊', '淄博']],
        // 华南省份
        'guangdong' => ['name' => '广东省', 'region' => '华南', 'industries' => ['电子信息', '家电制造', '五金机械', '塑胶包装', '通信设备'], 'cities' => ['深圳', '广州', '东莞', '佛山', '惠州']],
        'guangxi' => ['name' => '广西壮族自治区', 'region' => '华南', 'industries' => ['有色金属', '机械装备', '糖业林业', '建材陶瓷', '边境贸易'], 'cities' => ['南宁', '柳州', '桂林', '北海', '防城港']],
        'hainan' => ['name' => '海南省', 'region' => '华南', 'industries' => ['热带农产品', '海洋装备', '航天南繁', '游艇船舶', '健康医药'], 'cities' => ['海口', '三亚', '洋浦', '儋州', '澄迈']],
        // 华中省份
        'henan' => ['name' => '河南省', 'region' => '华中', 'industries' => ['食品加工', '装备制造', '有色金属', '建材陶瓷', '中原物流'], 'cities' => ['郑州', '洛阳', '南阳', '许昌', '新乡']],
        'hubei' => ['name' => '湖北省', 'region' => '华中', 'industries' => ['汽车装备', '光电信息', '钢铁化工', '生物医药', '航天装备'], 'cities' => ['武汉', '宜昌', '襄阳', '黄石', '荆州']],
        'hunan' => ['name' => '湖南省', 'region' => '华中', 'industries' => ['工程机械', '轨道交通', '钢铁有色', '电子信息', '食品加工'], 'cities' => ['长沙', '株洲', '湘潭', '岳阳', '衡阳']],
        // 华北省份
        'hebei' => ['name' => '河北省', 'region' => '华北', 'industries' => ['钢铁冶金', '装备制造', '化工医药', '建材陶瓷', '皮革纺织'], 'cities' => ['石家庄', '唐山', '保定', '邯郸', '廊坊']],
        'shanxi' => ['name' => '山西省', 'region' => '华北', 'industries' => ['煤炭化工', '钢铁冶金', '装备制造', '电力新能源', '酿造食品'], 'cities' => ['太原', '大同', '长治', '晋城', '运城']],
        'innermongolia' => ['name' => '内蒙古自治区', 'region' => '华北', 'industries' => ['煤炭电力', '稀土金属', '畜牧农产', '装备制造', '风电光伏'], 'cities' => ['呼和浩特', '包头', '鄂尔多斯', '赤峰', '通辽']],
        // 东北省份
        'liaoning' => ['name' => '辽宁省', 'region' => '东北', 'industries' => ['装备制造', '钢铁冶金', '石化化工', '船舶航空', '汽车零部件'], 'cities' => ['沈阳', '大连', '鞍山', '抚顺', '本溪']],
        'jilin' => ['name' => '吉林省', 'region' => '东北', 'industries' => ['汽车制造', '化工医药', '装备制造', '农产品加工', '电子信息'], 'cities' => ['长春', '吉林', '四平', '通化', '白山']],
        'heilongjiang' => ['name' => '黑龙江省', 'region' => '东北', 'industries' => ['装备制造', '能源化工', '食品加工', '木材加工', '寒带农业'], 'cities' => ['哈尔滨', '齐齐哈尔', '大庆', '牡丹江', '佳木斯']],
        // 西南省份
        'sichuan' => ['name' => '四川省', 'region' => '西南', 'industries' => ['电子信息', '装备制造', '能源化工', '食品饮料', '中医药材'], 'cities' => ['成都', '绵阳', '德阳', '宜宾', '泸州']],
        'guizhou' => ['name' => '贵州省', 'region' => '西南', 'industries' => ['白酒酿造', '能源煤化工', '有色冶金', '大数据电子', '中药材'], 'cities' => ['贵阳', '遵义', '安顺', '六盘水', '毕节']],
        'yunnan' => ['name' => '云南省', 'region' => '西南', 'industries' => ['有色金属', '烟草日化', '生物医药', '高原农产', '边境物流'], 'cities' => ['昆明', '曲靖', '玉溪', '楚雄', '红河']],
        'tibet' => ['name' => '西藏自治区', 'region' => '西南', 'industries' => ['特色农牧', '高原水电', '矿产开采', '藏药材', '边境贸易'], 'cities' => ['拉萨', '日喀则', '林芝', '山南', '昌都']],
        // 西北省份
        'shaanxi' => ['name' => '陕西省', 'region' => '西北', 'industries' => ['航空航天', '装备制造', '能源化工', '电子信息', '现代农业'], 'cities' => ['西安', '宝鸡', '咸阳', '渭南', '榆林']],
        'gansu' => ['name' => '甘肃省', 'region' => '西北', 'industries' => ['有色冶金', '石油化工', '装备制造', '中药材', '风电光伏'], 'cities' => ['兰州', '天水', '酒泉', '嘉峪关', '金昌']],
        'qinghai' => ['name' => '青海省', 'region' => '西北', 'industries' => ['盐湖化工', '有色金属', '清洁能源', '高原牧业', '生态产业'], 'cities' => ['西宁', '格尔木', '德令哈', '海东', '玉树']],
        'ningxia' => ['name' => '宁夏回族自治区', 'region' => '西北', 'industries' => ['煤化工', '冶金机械', '清真食品', '光伏新能源', '葡萄酒酿造'], 'cities' => ['银川', '吴忠', '石嘴山', '中卫', '固原']],
        'xinjiang' => ['name' => '新疆维吾尔自治区', 'region' => '西北', 'industries' => ['石油化工', '棉纺纺织', '风电光伏', '矿产冶金', '边境物流'], 'cities' => ['乌鲁木齐', '克拉玛依', '昌吉', '伊犁', '喀什']],
        // 港澳台
        'hongkong' => ['name' => '香港特别行政区', 'region' => '华南', 'industries' => ['国际转口', '金融贸易', '电子集散', '船舶港口', '专业服务'], 'cities' => ['香港', '九龙', '新界', '葵青', '将军澳']],
        'macao' => ['name' => '澳门特别行政区', 'region' => '华南', 'industries' => ['博彩文旅', '中葡贸易', '会展物流', '中医药', '食品加工'], 'cities' => ['澳门', '氹仔', '路环', '凼仔工业园', '横琴口岸']],
        'taiwan' => ['name' => '台湾省', 'region' => '华东', 'industries' => ['集成电路', '精密机械', '电子信息', '光电显示', '石化材料'], 'cities' => ['台北', '新北', '台中', '高雄', '桃园']],
    ];

    /** 50 个核心工业地市（带 -city 后缀避免与省级 slug 冲突，cities 字段填该市的区/县/产业带）*/
    private const CITIES = [
        // 长三角
        'hangzhou-city' => ['name' => '杭州市', 'region' => '浙江 · 华东', 'industries' => ['电商科技', '装备制造', '丝绸纺织', '生物医药', '工业自动化'], 'cities' => ['萧山', '余杭', '富阳', '临安', '滨江']],
        'ningbo-city' => ['name' => '宁波市', 'region' => '浙江 · 华东', 'industries' => ['石化新材', '汽车零部件', '装备制造', '家电模具', '港口物流'], 'cities' => ['北仑', '镇海', '慈溪', '余姚', '鄞州']],
        'wenzhou-city' => ['name' => '温州市', 'region' => '浙江 · 华东', 'industries' => ['五金电气', '鞋革服饰', '泵阀', '汽车摩托配件', '激光光电'], 'cities' => ['乐清', '瑞安', '永嘉', '苍南', '龙湾']],
        'shaoxing-city' => ['name' => '绍兴市', 'region' => '浙江 · 华东', 'industries' => ['纺织印染', '化工医药', '装备制造', '黄酒食品', '电子元器件'], 'cities' => ['柯桥', '诸暨', '上虞', '嵊州', '新昌']],
        'taizhou-city' => ['name' => '台州市', 'region' => '浙江 · 华东', 'industries' => ['模具塑料', '医药化工', '汽车零部件', '泵阀', '缝纫机'], 'cities' => ['黄岩', '路桥', '温岭', '玉环', '临海']],
        'wuxi-city' => ['name' => '无锡市', 'region' => '江苏 · 华东', 'industries' => ['集成电路', '装备制造', '纺织化纤', '光伏新能源', '物联网'], 'cities' => ['江阴', '宜兴', '滨湖', '锡山', '惠山']],
        'changzhou-city' => ['name' => '常州市', 'region' => '江苏 · 华东', 'industries' => ['装备制造', '新材料', '动力电池', '光伏', '机器人'], 'cities' => ['武进', '金坛', '溧阳', '钟楼', '天宁']],
        'nantong-city' => ['name' => '南通市', 'region' => '江苏 · 华东', 'industries' => ['船舶海工', '纺织家纺', '装备制造', '化工新材', '电子信息'], 'cities' => ['启东', '海门', '通州', '如皋', '海安']],
        'xuzhou-city' => ['name' => '徐州市', 'region' => '江苏 · 华东', 'industries' => ['工程机械', '矿山装备', '钢铁化工', '物流装备', '农业机械'], 'cities' => ['鼓楼', '云龙', '邳州', '新沂', '丰县']],
        'yangzhou-city' => ['name' => '扬州市', 'region' => '江苏 · 华东', 'industries' => ['装备制造', '汽车零部件', '化工新材', '电子信息', '玩具旅游用品'], 'cities' => ['仪征', '高邮', '宝应', '邗江', '广陵']],
        'hefei-city' => ['name' => '合肥市', 'region' => '安徽 · 华东', 'industries' => ['家电制造', '集成电路', '新能源汽车', '装备制造', '量子科技'], 'cities' => ['蜀山', '包河', '肥东', '肥西', '长丰']],
        // 珠三角
        'guangzhou-city' => ['name' => '广州市', 'region' => '广东 · 华南', 'industries' => ['汽车制造', '电子信息', '石化新材', '生物医药', '美妆日化'], 'cities' => ['南沙', '番禺', '增城', '从化', '黄埔']],
        'foshan-city' => ['name' => '佛山市', 'region' => '广东 · 华南', 'industries' => ['家电制造', '陶瓷建材', '装备制造', '铝型材', '家具'], 'cities' => ['顺德', '南海', '禅城', '高明', '三水']],
        'dongguan-city' => ['name' => '东莞市', 'region' => '广东 · 华南', 'industries' => ['电子信息', '装备制造', '玩具家具', '纺织服装', '塑胶模具'], 'cities' => ['长安', '虎门', '塘厦', '常平', '松山湖']],
        'zhongshan-city' => ['name' => '中山市', 'region' => '广东 · 华南', 'industries' => ['灯饰照明', '五金锁具', '家电', '装备制造', '健康医药'], 'cities' => ['古镇', '小榄', '横栏', '南头', '黄圃']],
        'huizhou-city' => ['name' => '惠州市', 'region' => '广东 · 华南', 'industries' => ['电子信息', '石化新材', '汽车零部件', '清洁能源', '智能装备'], 'cities' => ['仲恺', '大亚湾', '惠阳', '博罗', '惠东']],
        'zhuhai-city' => ['name' => '珠海市', 'region' => '广东 · 华南', 'industries' => ['电子信息', '生物医药', '海洋装备', '家电制造', '航空通用'], 'cities' => ['香洲', '金湾', '斗门', '高新区', '横琴']],
        // 山东
        'jinan-city' => ['name' => '济南市', 'region' => '山东 · 华东', 'industries' => ['装备制造', '生物医药', '汽车制造', '钢铁化工', '电子信息'], 'cities' => ['历下', '市中', '槐荫', '章丘', '济阳']],
        'yantai-city' => ['name' => '烟台市', 'region' => '山东 · 华东', 'industries' => ['汽车制造', '装备制造', '电子信息', '海洋食品', '黄金有色'], 'cities' => ['芝罘', '福山', '牟平', '蓬莱', '龙口']],
        'weifang-city' => ['name' => '潍坊市', 'region' => '山东 · 华东', 'industries' => ['装备制造', '化工新材', '纺织服装', '汽车零部件', '农产食品'], 'cities' => ['寒亭', '坊子', '青州', '诸城', '寿光']],
        'zibo-city' => ['name' => '淄博市', 'region' => '山东 · 华东', 'industries' => ['化工新材', '装备制造', '建陶玻璃', '医药', '钢铁有色'], 'cities' => ['张店', '临淄', '周村', '桓台', '高青']],
        // 京津冀
        'tangshan-city' => ['name' => '唐山市', 'region' => '河北 · 华北', 'industries' => ['钢铁冶金', '装备制造', '陶瓷建材', '化工', '能源港口'], 'cities' => ['路北', '路南', '丰南', '迁安', '滦州']],
        'baoding-city' => ['name' => '保定市', 'region' => '河北 · 华北', 'industries' => ['汽车制造', '新能源', '装备制造', '纺织服装', '食品加工'], 'cities' => ['竞秀', '莲池', '高碑店', '定州', '涿州']],
        'shijiazhuang-city' => ['name' => '石家庄市', 'region' => '河北 · 华北', 'industries' => ['生物医药', '装备制造', '纺织服装', '钢铁化工', '电子信息'], 'cities' => ['长安', '桥西', '裕华', '正定', '鹿泉']],
        'langfang-city' => ['name' => '廊坊市', 'region' => '河北 · 华北', 'industries' => ['电子信息', '汽车零部件', '生物医药', '新能源', '现代物流'], 'cities' => ['广阳', '安次', '香河', '霸州', '三河']],
        // 中部
        'luoyang-city' => ['name' => '洛阳市', 'region' => '河南 · 华中', 'industries' => ['装备制造', '有色金属', '石化化工', '机器人', '硅光伏'], 'cities' => ['涧西', '老城', '西工', '伊滨', '偃师']],
        'xinxiang-city' => ['name' => '新乡市', 'region' => '河南 · 华中', 'industries' => ['装备制造', '生物医药', '电池新能源', '纺织化工', '汽车零部件'], 'cities' => ['红旗', '卫滨', '凤泉', '新乡县', '辉县']],
        'xuchang-city' => ['name' => '许昌市', 'region' => '河南 · 华中', 'industries' => ['装备制造', '电气电缆', '发制品', '食品医药', '建材陶瓷'], 'cities' => ['魏都', '建安', '禹州', '长葛', '鄢陵']],
        'yichang-city' => ['name' => '宜昌市', 'region' => '湖北 · 华中', 'industries' => ['化工新材', '装备制造', '生物医药', '电子信息', '食品饮料'], 'cities' => ['西陵', '伍家岗', '点军', '宜都', '当阳']],
        'zhuzhou-city' => ['name' => '株洲市', 'region' => '湖南 · 华中', 'industries' => ['轨道交通', '航空动力', '汽车装备', '化工新材', '陶瓷服饰'], 'cities' => ['天元', '荷塘', '芦淞', '醴陵', '攸县']],
        'xiangtan-city' => ['name' => '湘潭市', 'region' => '湖南 · 华中', 'industries' => ['装备制造', '汽车制造', '钢铁有色', '精细化工', '军民融合'], 'cities' => ['雨湖', '岳塘', '韶山', '湘潭县', '湘乡']],
        // 西部
        'mianyang-city' => ['name' => '绵阳市', 'region' => '四川 · 西南', 'industries' => ['电子信息', '装备制造', '材料化工', '汽车制造', '军民融合'], 'cities' => ['涪城', '游仙', '安州', '江油', '北川']],
        'deyang-city' => ['name' => '德阳市', 'region' => '四川 · 西南', 'industries' => ['重大装备', '机械制造', '油气钻采', '化工新材', '食品饮料'], 'cities' => ['旌阳', '罗江', '广汉', '什邡', '绵竹']],
        'baoji-city' => ['name' => '宝鸡市', 'region' => '陕西 · 西北', 'industries' => ['装备制造', '钛及钛合金', '汽车制造', '能源化工', '电子信息'], 'cities' => ['渭滨', '金台', '陈仓', '凤翔', '岐山']],
        'lanzhou-city' => ['name' => '兰州市', 'region' => '甘肃 · 西北', 'industries' => ['石油化工', '有色冶金', '装备制造', '生物医药', '新能源'], 'cities' => ['城关', '七里河', '安宁', '西固', '榆中']],
        // 东北
        'dalian-city' => ['name' => '大连市', 'region' => '辽宁 · 东北', 'industries' => ['船舶海工', '装备制造', '石化化工', '电子信息', '食品加工'], 'cities' => ['中山', '西岗', '甘井子', '金州', '旅顺']],
        'changchun-city' => ['name' => '长春市', 'region' => '吉林 · 东北', 'industries' => ['汽车制造', '轨道客车', '装备制造', '生物医药', '电子信息'], 'cities' => ['朝阳', '宽城', '二道', '德惠', '榆树']],
        'anshan-city' => ['name' => '鞍山市', 'region' => '辽宁 · 东北', 'industries' => ['钢铁冶金', '装备制造', '菱镁矿产', '化工新材', '机器人'], 'cities' => ['铁东', '铁西', '立山', '海城', '台安']],
        // 港口型
        'qingdao-city' => ['name' => '青岛市', 'region' => '山东 · 华东', 'industries' => ['家电电子', '橡胶轮胎', '海洋装备', '汽车制造', '食品饮料'], 'cities' => ['市南', '市北', '黄岛', '崂山', '即墨']],
        'tianjin-city' => ['name' => '天津市核心区', 'region' => '天津 · 华北', 'industries' => ['航空装备', '汽车制造', '石油化工', '电子信息', '装备制造'], 'cities' => ['和平', '河西', '南开', '滨海新区', '津南']],
        // 直辖市重点区
        'pudong-shanghai' => ['name' => '上海市浦东新区', 'region' => '上海 · 华东', 'industries' => ['集成电路', '生物医药', '航空航天', '金融科技', '装备制造'], 'cities' => ['张江', '金桥', '外高桥', '临港', '陆家嘴']],
        'jiading-shanghai' => ['name' => '上海市嘉定区', 'region' => '上海 · 华东', 'industries' => ['汽车制造', '智能传感', '装备制造', '机器人', '新材料'], 'cities' => ['南翔', '安亭', '马陆', '江桥', '外冈']],
        'songjiang-shanghai' => ['name' => '上海市松江区', 'region' => '上海 · 华东', 'industries' => ['集成电路', '电子信息', '生物医药', '装备制造', '汽车零部件'], 'cities' => ['G60科创走廊', '新桥', '九亭', '泖港', '佘山']],
        // 西南制造
        'liuzhou-city' => ['name' => '柳州市', 'region' => '广西 · 华南', 'industries' => ['汽车制造', '装备机械', '钢铁冶金', '化工新材', '日化食品'], 'cities' => ['城中', '柳南', '柳北', '柳东', '鹿寨']],
        'kunming-city' => ['name' => '昆明市', 'region' => '云南 · 西南', 'industries' => ['有色金属', '生物医药', '装备制造', '烟草日化', '高原农产'], 'cities' => ['五华', '盘龙', '官渡', '呈贡', '安宁']],
        'guiyang-city' => ['name' => '贵阳市', 'region' => '贵州 · 西南', 'industries' => ['大数据电子', '装备制造', '医药化工', '航空航天', '特色食品'], 'cities' => ['南明', '云岩', '观山湖', '清镇', '修文']],
        // 华南/福建
        'quanzhou-city' => ['name' => '泉州市', 'region' => '福建 · 华东', 'industries' => ['鞋服纺织', '建材石材', '装备机械', '食品工艺', '电子信息'], 'cities' => ['鲤城', '丰泽', '晋江', '石狮', '南安']],
        'fuzhou-city' => ['name' => '福州市', 'region' => '福建 · 华东', 'industries' => ['电子信息', '纺织化纤', '装备制造', '建材冶金', '海产食品'], 'cities' => ['鼓楼', '台江', '仓山', '马尾', '长乐']],
        // 中部其它
        'pingdingshan-city' => ['name' => '平顶山市', 'region' => '河南 · 华中', 'industries' => ['煤化工', '钢铁冶金', '装备制造', '电力新材', '尼龙特种'], 'cities' => ['新华', '卫东', '湛河', '舞钢', '汝州']],
        'zhuhai-zhongshan' => ['name' => '中山港珠澳通道带', 'region' => '广东 · 华南', 'industries' => ['跨境物流', '智能装备', '海洋经济', '生物医药', '会展贸易'], 'cities' => ['横琴', '中山港', '珠海港', '小榄', '高栏港']],
        // 长三角扩
        'suzhou-city' => ['name' => '苏州市', 'region' => '江苏 · 华东', 'industries' => ['电子信息', '装备制造', '生物医药', '纳米材料', '丝绸纺织'], 'cities' => ['工业园区', '吴江', '昆山', '太仓', '常熟']],
        'nanjing-city' => ['name' => '南京市', 'region' => '江苏 · 华东', 'industries' => ['电子信息', '汽车制造', '石化新材', '智能装备', '生物医药'], 'cities' => ['江宁', '浦口', '六合', '溧水', '高淳']],
        'zhenjiang-city' => ['name' => '镇江市', 'region' => '江苏 · 华东', 'industries' => ['装备制造', '航空航天', '新材料', '汽车零部件', '船舶海工'], 'cities' => ['丹阳', '扬中', '句容', '京口', '润州']],
        'taicang-city' => ['name' => '太仓市', 'region' => '江苏 · 华东', 'industries' => ['德资装备', '高端制造', '港口物流', '生物医药', '航空零部件'], 'cities' => ['娄东', '城厢', '璜泾', '沙溪', '浮桥']],
        'kunshan-city' => ['name' => '昆山市', 'region' => '江苏 · 华东', 'industries' => ['台资电子', '装备制造', '光电信息', '新材料', '智能机器人'], 'cities' => ['玉山', '花桥', '巴城', '千灯', '锦溪']],
        'jiangyin-city' => ['name' => '江阴市', 'region' => '江苏 · 华东', 'industries' => ['特钢冶金', '纺织化纤', '装备制造', '生物医药', '集成电路'], 'cities' => ['澄江', '云亭', '夏港', '璜土', '徐霞客']],
        'jiaxing-city' => ['name' => '嘉兴市', 'region' => '浙江 · 华东', 'industries' => ['光伏新能源', '电子信息', '化纤纺织', '装备制造', '智能家居'], 'cities' => ['南湖', '秀洲', '海宁', '桐乡', '嘉善']],
        'huzhou-city' => ['name' => '湖州市', 'region' => '浙江 · 华东', 'industries' => ['绿色家居', '装备制造', '生物医药', '物流装备', '新材料'], 'cities' => ['吴兴', '南浔', '德清', '长兴', '安吉']],
        'jinhua-city' => ['name' => '金华市', 'region' => '浙江 · 华东', 'industries' => ['汽车零部件', '五金工具', '电动工具', '医药化工', '小商品'], 'cities' => ['婺城', '金东', '义乌', '永康', '兰溪']],
        'lishui-city' => ['name' => '丽水市', 'region' => '浙江 · 华东', 'industries' => ['不锈钢', '合成革', '机电制造', '生物医药', '生态食品'], 'cities' => ['莲都', '青田', '缙云', '龙泉', '云和']],
        'huaian-city' => ['name' => '淮安市', 'region' => '江苏 · 华东', 'industries' => ['盐化工新材', '装备制造', '电子信息', '食品加工', '物流装备'], 'cities' => ['清江浦', '淮阴', '涟水', '盱眙', '金湖']],
        'yancheng-city' => ['name' => '盐城市', 'region' => '江苏 · 华东', 'industries' => ['新能源汽车', '海上风电', '装备制造', '电子信息', '钢铁冶金'], 'cities' => ['亭湖', '盐都', '东台', '建湖', '大丰']],
        'lianyungang-city' => ['name' => '连云港市', 'region' => '江苏 · 华东', 'industries' => ['石化新材', '生物医药', '装备制造', '港口物流', '海洋经济'], 'cities' => ['海州', '连云', '赣榆', '东海', '灌云']],
        // 珠三角扩
        'shenzhen-city' => ['name' => '深圳市', 'region' => '广东 · 华南', 'industries' => ['电子信息', '新一代通信', '智能终端', '生物医药', '工业机器人'], 'cities' => ['南山', '宝安', '龙岗', '光明', '坪山']],
        'jiangmen-city' => ['name' => '江门市', 'region' => '广东 · 华南', 'industries' => ['五金不锈钢', '摩托车装备', '电子信息', '造纸印刷', '食品保健'], 'cities' => ['蓬江', '江海', '新会', '台山', '开平']],
        'shantou-city' => ['name' => '汕头市', 'region' => '广东 · 华南', 'industries' => ['玩具创意', '化工塑料', '纺织服装', '电子信息', '装备制造'], 'cities' => ['金平', '龙湖', '澄海', '潮阳', '潮南']],
        'maoming-city' => ['name' => '茂名市', 'region' => '广东 · 华南', 'industries' => ['石油化工', '高端石化新材', '现代农业', '海洋装备', '建材陶瓷'], 'cities' => ['茂南', '电白', '高州', '化州', '信宜']],
        'zhaoqing-city' => ['name' => '肇庆市', 'region' => '广东 · 华南', 'industries' => ['新能源汽车', '装备制造', '电子信息', '新材料', '健康食品'], 'cities' => ['端州', '鼎湖', '高要', '四会', '广宁']],
        'shaoguan-city' => ['name' => '韶关市', 'region' => '广东 · 华南', 'industries' => ['钢铁有色', '装备制造', '电子信息', '生物医药', '绿色食品'], 'cities' => ['浈江', '武江', '曲江', '乳源', '新丰']],
        // 山东扩
        'linyi-city' => ['name' => '临沂市', 'region' => '山东 · 华东', 'industries' => ['物流商贸', '装备制造', '化工新材', '木业建材', '食品加工'], 'cities' => ['兰山', '罗庄', '河东', '郯城', '沂南']],
        'jining-city' => ['name' => '济宁市', 'region' => '山东 · 华东', 'industries' => ['工程机械', '装备制造', '煤化工', '食品加工', '光电信息'], 'cities' => ['任城', '兖州', '邹城', '曲阜', '微山']],
        'dongying-city' => ['name' => '东营市', 'region' => '山东 · 华东', 'industries' => ['石油石化', '橡胶轮胎', '有色金属', '生态化工', '装备制造'], 'cities' => ['东营区', '河口', '垦利', '广饶', '利津']],
        'binzhou-city' => ['name' => '滨州市', 'region' => '山东 · 华东', 'industries' => ['高端铝业', '纺织家纺', '化工医药', '装备制造', '海洋经济'], 'cities' => ['滨城', '邹平', '惠民', '阳信', '沾化']],
        'liaocheng-city' => ['name' => '聊城市', 'region' => '山东 · 华东', 'industries' => ['有色金属', '汽车零部件', '装备制造', '化工新材', '农副产品'], 'cities' => ['东昌府', '临清', '冠县', '茌平', '高唐']],
        // 京津冀扩
        'qinhuangdao-city' => ['name' => '秦皇岛市', 'region' => '河北 · 华北', 'industries' => ['玻璃建材', '装备制造', '汽车零部件', '能源港口', '海洋食品'], 'cities' => ['海港', '北戴河', '山海关', '昌黎', '抚宁']],
        'cangzhou-city' => ['name' => '沧州市', 'region' => '河北 · 华北', 'industries' => ['石油化工', '管道装备', '机械制造', '生物医药', '汽车模具'], 'cities' => ['运河', '新华', '泊头', '黄骅', '任丘']],
        'handan-city' => ['name' => '邯郸市', 'region' => '河北 · 华北', 'industries' => ['钢铁冶金', '装备制造', '陶瓷新材', '纺织服装', '食品医药'], 'cities' => ['丛台', '邯山', '武安', '永年', '魏县']],
        'xingtai-city' => ['name' => '邢台市', 'region' => '河北 · 华北', 'industries' => ['装备制造', '汽车零部件', '钢铁冶金', '化工医药', '农产食品'], 'cities' => ['襄都', '信都', '南宫', '沙河', '柏乡']],
        // 中部扩
        'zhengzhou-city' => ['name' => '郑州市', 'region' => '河南 · 华中', 'industries' => ['汽车制造', '装备制造', '电子信息', '现代食品', '现代物流'], 'cities' => ['中原', '金水', '航空港', '荥阳', '新郑']],
        'kaifeng-city' => ['name' => '开封市', 'region' => '河南 · 华中', 'industries' => ['农副食品', '装备制造', '生物医药', '化工新材', '汽车零部件'], 'cities' => ['鼓楼', '龙亭', '尉氏', '通许', '杞县']],
        'nanyang-city' => ['name' => '南阳市', 'region' => '河南 · 华中', 'industries' => ['装备制造', '光电信息', '生物医药', '纺织服装', '食品加工'], 'cities' => ['宛城', '卧龙', '邓州', '南召', '西峡']],
        'zhoukou-city' => ['name' => '周口市', 'region' => '河南 · 华中', 'industries' => ['食品加工', '纺织服装', '装备制造', '生物医药', '物流装备'], 'cities' => ['川汇', '项城', '商水', '太康', '鹿邑']],
        'wuhan-city' => ['name' => '武汉市', 'region' => '湖北 · 华中', 'industries' => ['汽车制造', '光电信息', '生物医药', '钢铁化工', '装备制造'], 'cities' => ['江岸', '武昌', '硚口', '东西湖', '光谷']],
        'xiangyang-city' => ['name' => '襄阳市', 'region' => '湖北 · 华中', 'industries' => ['汽车制造', '装备制造', '新能源汽车', '化工新材', '农副食品'], 'cities' => ['襄城', '樊城', '老河口', '枣阳', '宜城']],
        'changsha-city' => ['name' => '长沙市', 'region' => '湖南 · 华中', 'industries' => ['工程机械', '汽车制造', '电子信息', '生物医药', '新材料'], 'cities' => ['岳麓', '雨花', '望城', '长沙县', '宁乡']],
        'yueyang-city' => ['name' => '岳阳市', 'region' => '湖南 · 华中', 'industries' => ['石化新材', '装备制造', '生物医药', '电子信息', '港口物流'], 'cities' => ['岳阳楼', '云溪', '汨罗', '临湘', '岳阳县']],
        // 西部扩
        'chengdu-city' => ['name' => '成都市', 'region' => '四川 · 西南', 'industries' => ['电子信息', '装备制造', '汽车制造', '生物医药', '航空航天'], 'cities' => ['锦江', '青羊', '武侯', '高新', '天府新区']],
        'xian-city' => ['name' => '西安市', 'region' => '陕西 · 西北', 'industries' => ['航空航天', '电子信息', '装备制造', '汽车制造', '新材料'], 'cities' => ['雁塔', '碑林', '高新', '经开', '阎良']],
        'nanchang-city' => ['name' => '南昌市', 'region' => '江西 · 华东', 'industries' => ['汽车制造', '航空装备', '电子信息', '生物医药', '新材料'], 'cities' => ['东湖', '红谷滩', '青云谱', '新建', '南昌县']],
        'nanning-city' => ['name' => '南宁市', 'region' => '广西 · 华南', 'industries' => ['电子信息', '生物医药', '装备制造', '铝精深加工', '现代农业'], 'cities' => ['青秀', '西乡塘', '良庆', '兴宁', '武鸣']],
        'urumqi-city' => ['name' => '乌鲁木齐市', 'region' => '新疆 · 西北', 'industries' => ['石油化工', '装备制造', '新材料', '风电光伏', '现代物流'], 'cities' => ['天山', '沙依巴克', '高新', '经开', '米东']],
        'yinchuan-city' => ['name' => '银川市', 'region' => '宁夏 · 西北', 'industries' => ['现代煤化工', '装备制造', '新材料', '光伏储能', '葡萄酒'], 'cities' => ['兴庆', '金凤', '西夏', '永宁', '贺兰']],
        'xining-city' => ['name' => '西宁市', 'region' => '青海 · 西北', 'industries' => ['有色金属', '盐湖化工', '光伏新材', '装备制造', '生物医药'], 'cities' => ['城东', '城中', '城西', '城北', '湟中']],
        // 东北扩
        'shenyang-city' => ['name' => '沈阳市', 'region' => '辽宁 · 东北', 'industries' => ['装备制造', '汽车制造', '航空航天', '电子信息', '生物医药'], 'cities' => ['和平', '沈河', '铁西', '浑南', '法库']],
        'jilin-city' => ['name' => '吉林市', 'region' => '吉林 · 东北', 'industries' => ['石油化工', '装备制造', '汽车零部件', '新材料', '冶金建材'], 'cities' => ['船营', '昌邑', '龙潭', '丰满', '永吉']],
        'haerbin-city' => ['name' => '哈尔滨市', 'region' => '黑龙江 · 东北', 'industries' => ['装备制造', '汽车制造', '生物医药', '食品加工', '航空航天'], 'cities' => ['南岗', '道里', '香坊', '松北', '阿城']],
        'daqing-city' => ['name' => '大庆市', 'region' => '黑龙江 · 东北', 'industries' => ['石油化工', '装备制造', '新材料', '生物医药', '汽车制造'], 'cities' => ['萨尔图', '红岗', '让胡路', '龙凤', '大同']],
        // 西南扩
        'chongqing-city' => ['name' => '重庆市核心区', 'region' => '重庆 · 西南', 'industries' => ['汽车摩托车', '电子信息', '装备制造', '材料化工', '消费电子'], 'cities' => ['渝中', '江北', '南岸', '九龙坡', '两江新区']],
        'kunming-anning' => ['name' => '昆明安宁工业带', 'region' => '云南 · 西南', 'industries' => ['有色冶金', '化工新材', '装备制造', '生物医药', '建材陶瓷'], 'cities' => ['安宁', '太平', '县街', '草铺', '青龙']],
        'lhasa-city' => ['name' => '拉萨市', 'region' => '西藏 · 西南', 'industries' => ['特色农产', '藏医药', '新能源', '高原食品', '装备制造'], 'cities' => ['城关', '堆龙德庆', '达孜', '林周', '尼木']],
        // 港口/能源型扩
        'ningde-city' => ['name' => '宁德市', 'region' => '福建 · 华东', 'industries' => ['锂电新能源', '不锈钢', '装备制造', '新材料', '海洋经济'], 'cities' => ['蕉城', '东侨', '福安', '福鼎', '霞浦']],
        'rizhao-city' => ['name' => '日照市', 'region' => '山东 · 华东', 'industries' => ['钢铁港口', '汽车零部件', '装备制造', '海洋经济', '粮油食品'], 'cities' => ['东港', '岚山', '莒县', '五莲', '日照港']],
        'weihai-city' => ['name' => '威海市', 'region' => '山东 · 华东', 'industries' => ['海洋食品', '装备制造', '电子信息', '碳纤维新材', '生物医药'], 'cities' => ['环翠', '文登', '荣成', '乳山', '威海港']],
        'zhanjiang-city' => ['name' => '湛江市', 'region' => '广东 · 华南', 'industries' => ['钢铁石化', '海洋装备', '海产食品', '现代物流', '生物医药'], 'cities' => ['赤坎', '霞山', '坡头', '麻章', '吴川']],
        'beihai-city' => ['name' => '北海市', 'region' => '广西 · 华南', 'industries' => ['电子信息', '石油化工', '海产食品', '装备制造', '生物医药'], 'cities' => ['海城', '银海', '铁山港', '合浦', '北海港']],
        'fangchenggang-city' => ['name' => '防城港市', 'region' => '广西 · 华南', 'industries' => ['钢铁有色', '石油化工', '装备制造', '港口物流', '海洋经济'], 'cities' => ['港口', '防城', '上思', '东兴', '北部湾']],
        'rugao-city' => ['name' => '如皋市', 'region' => '江苏 · 华东', 'industries' => ['汽车零部件', '装备制造', '化工新材', '电子信息', '食品加工'], 'cities' => ['如城', '城北', '搬经', '吴窑', '长江镇']],
    ];

    private string $apiUrl;
    private string $apiKey;
    private string $model;
    private int $concurrency;
    private int $limit;
    private bool $force;
    private bool $dryRun;
    private ?string $only;

    public function __construct(array $opts)
    {
        $this->apiUrl = rtrim(LLM_API_URL, '/') . '/chat/completions';
        $this->apiKey = LLM_API_KEY;
        $this->model = LLM_MODEL;
        $this->concurrency = max(1, min(50, (int)($opts['concurrency'] ?? 12)));
        $this->limit = max(1, (int)($opts['limit'] ?? 50));
        $this->force = !empty($opts['force']);
        $this->dryRun = !empty($opts['dry-run']);
        $this->only = isset($opts['only']) ? (string)$opts['only'] : null;
        $this->target = (string)($opts['target'] ?? 'provinces'); // provinces|cities|both
    }

    private string $target;

    public function run(): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

        $candidates = [];
        if ($this->target === 'provinces' || $this->target === 'both') {
            $candidates = array_merge($candidates, self::PROVINCES);
        }
        if ($this->target === 'cities' || $this->target === 'both') {
            $candidates = array_merge($candidates, self::CITIES);
        }
        if ($this->only !== null) {
            $candidates = isset($candidates[$this->only]) ? [$this->only => $candidates[$this->only]] : [];
        }
        $jobs = [];
        foreach ($candidates as $slug => $meta) {
            $path = $outDir . '/' . $slug . '.html';
            if (!$this->force && is_file($path)) continue;
            $jobs[] = ['slug' => $slug, 'meta' => $meta, 'path' => $path];
            if (count($jobs) >= $this->limit) break;
        }
        $this->log(sprintf('jobs=%d concurrency=%d dry=%s force=%s', count($jobs), $this->concurrency, $this->dryRun ? 'y' : 'n', $this->force ? 'y' : 'n'));
        if (!$jobs) return;

        $stats = ['ok' => 0, 'fail_http' => 0, 'fail_parse' => 0];
        $batches = array_chunk($jobs, $this->concurrency, false);
        foreach ($batches as $bi => $batch) {
            $t0 = microtime(true);
            $results = $this->callBatch($batch);
            foreach ($results as $idx => $resp) {
                $job = $batch[$idx];
                if ($resp['err'] !== '') {
                    $stats['fail_http']++;
                    $this->log("FAIL HTTP slug={$job['slug']} err={$resp['err']}");
                    continue;
                }
                $parsed = $this->parseResponse((string)$resp['body']);
                if (!is_array($parsed) || !isset($parsed['intro']) || count($parsed['focus'] ?? []) < 3) {
                    $stats['fail_parse']++;
                    @file_put_contents(ROOT_PATH . '/runtime/geo_fail_' . $job['slug'] . '.txt', substr((string)$resp['body'], 0, 8000));
                    $this->log("FAIL PARSE slug={$job['slug']}");
                    continue;
                }
                if ($this->dryRun) {
                    $this->log("DRY slug={$job['slug']} intro_len=" . mb_strlen($parsed['intro'], 'UTF-8'));
                } else {
                    $html = $this->renderHtml($job['slug'], $job['meta'], $parsed);
                    file_put_contents($job['path'], $html);
                    $this->log("OK slug={$job['slug']} bytes=" . strlen($html));
                }
                $stats['ok']++;
            }
            $dt = round((microtime(true) - $t0) * 1000);
            $this->log(sprintf('batch %d done in %dms', $bi + 1, $dt));
        }
        $this->log('STATS ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int,array{err:string,body:string}> */
    private function callBatch(array $batch): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $idx => $job) {
            $prompt = $this->buildPrompt($job);
            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => LLMPromptKit::buildSystem('industrial_belt_researcher', ['anti_ai_slop', 'geo_locality', 'no_fabrication', 'no_markdown', 'strict_json'])],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => LLMPromptKit::temperature('geo'),
                'max_tokens' => 5500,
            ];
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1);
        } while ($running > 0);

        $out = [];
        foreach ($handles as $idx => $ch) {
            $body = (string)curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = (string)curl_error($ch);
            $err = '';
            if ($cerr !== '') $err = 'curl:' . $cerr;
            elseif ($code !== 200) $err = 'http_' . $code;
            $out[$idx] = ['err' => $err, 'body' => $body];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function buildPrompt(array $job): string
    {
        $m = $job['meta'];
        $industries = implode('、', $m['industries']);
        $cities = implode('、', $m['cities']);
        $rules = LLMPromptKit::rules(['anti_ai_slop', 'geo_locality', 'industrial_quantify', 'no_fabrication']);
        $checklist = LLMPromptKit::checklist('geo');
        return $rules . "\n\n"
            . "【本次任务】为采购导航站撰写「{$m['name']}（{$m['region']}）工业采购导航」内容。\n"
            . "聚焦行业：{$industries}\n"
            . "重点城市：{$cities}\n\n"
            . $checklist . "\n\n"
            . "严格返回如下 JSON（不要任何解释、不要 Markdown 代码块）：\n"
            . "{\n"
            . '  "intro": "120-160字开篇导读，描述该省份在工业采购上的地位、优势行业、产业带分布",' . "\n"
            . '  "focus": [{"title":"行业名","desc":"55-80字本省该行业典型产品、龙头城市、采购重点"}, ...共5个],' . "\n"
            . '  "checklist": ["要点1（30-60字）", "要点2", "要点3", "要点4", "要点5"],' . "\n"
            . '  "faqs": [{"q":"问题1","a":"80-120字回答"}, {"q":"问题2","a":"..."}, {"q":"问题3","a":"..."}, {"q":"问题4","a":"..."}, {"q":"问题5","a":"..."}]' . "\n"
            . "}";
    }

    private function parseResponse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) return null;
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') return null;
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content) ?: $content;
        $content = trim($content);
        // 清洗 glm-4.6 偶发返回的非法 UTF-8 半字符（如 "潍坊市\xEF\xBF\xBD"），改成 mb_convert
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }
        // 替换 U+FFFD 替换字符（json_decode 会拒绝）
        $content = preg_replace('/\x{FFFD}/u', '', $content) ?? $content;
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            // 修复 glm-4.6 偶发在 JSON 字符串内吐 raw newline / tab 的问题：把字符串内未转义控制字符转义
            $fixed = $this->escapeControlInJsonStrings($content);
            $parsed = json_decode($fixed, true);
        }
        if (!is_array($parsed)) {
            // 尝试修补尾部
            $content2 = rtrim($content);
            if (substr($content2, -1) !== '}') $content2 .= '}';
            $parsed = json_decode($content2, true);
        }
        return is_array($parsed) ? $parsed : null;
    }

    /** 把 JSON 字符串字面量内未转义的换行/制表符替换为 \n / \t，让 json_decode 通过 */
    private function escapeControlInJsonStrings(string $s): string
    {
        $out = '';
        $inStr = false;
        $escape = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = $s[$i];
            $o = ord($b);
            if ($escape) { $out .= $b; $escape = false; continue; }
            if ($inStr) {
                if ($b === "\\") { $out .= $b; $escape = true; continue; }
                if ($b === '"') { $out .= $b; $inStr = false; continue; }
                if ($o === 0x0A) { $out .= '\\n'; continue; }
                if ($o === 0x0D) { $out .= '\\r'; continue; }
                if ($o === 0x09) { $out .= '\\t'; continue; }
                if ($o < 0x20) { $out .= sprintf('\\u%04x', $o); continue; }
                $out .= $b;
            } else {
                if ($b === '"') { $inStr = true; }
                $out .= $b;
            }
        }
        return $out;
    }

    private function renderHtml(string $slug, array $meta, array $data): string
    {
        $name = $meta['name'];
        $region = $meta['region'];
        $industries = $meta['industries'];
        $cities = $meta['cities'];
        $intro = (string)($data['intro'] ?? '');
        $focus = (array)($data['focus'] ?? []);
        $checklist = (array)($data['checklist'] ?? []);
        $faqs = (array)($data['faqs'] ?? []);

        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $url = static fn($s) => urlencode((string)$s);

        $title = "{$name}工业采购导航：行业产业带与城市供应入口";
        $desc = mb_substr($intro, 0, 150, 'UTF-8');
        $kw = "{$name}工业采购," . implode(',', $industries) . ',' . implode(',', array_slice($cities, 0, 3));
        $canonical = "https://guonika.com/topics/geo/{$slug}.html";

        $chips = '';
        foreach ($cities as $c) $chips .= '<span class="geo-hub-chip">' . $h($c) . '</span>';

        $focusHtml = '';
        foreach ($focus as $f) {
            $ft = $h($f['title'] ?? '');
            $fd = $h($f['desc'] ?? '');
            $focusHtml .= '<div class="geo-focus-item"><strong>' . $ft . '</strong><p>' . $fd . '</p></div>';
        }

        $checklistHtml = '';
        foreach ($checklist as $c) {
            $checklistHtml .= '<li>' . $h($c) . '</li>';
        }

        $faqHtml = '';
        $faqSchemaItems = [];
        foreach ($faqs as $f) {
            $q = (string)($f['q'] ?? '');
            $a = (string)($f['a'] ?? '');
            if ($q === '' || $a === '') continue;
            $faqHtml .= '<h3>' . $h($q) . '</h3><p>' . $h($a) . '</p>';
            $faqSchemaItems[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
        $faqSchema = $faqSchemaItems
            ? json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqSchemaItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $cityListHtml = '';
        foreach ($cities as $c) {
            $cityListHtml .= '<a href="/search?q=' . $url($c . ' 工业采购') . '">' . $h($c) . ' 工业采购</a>';
        }

        $relatedQueries = [
            ['kw' => "{$name}工业自动化", 'href' => '/search?q=' . $url("{$name} 工业自动化")],
            ['kw' => "{$name}厂家供应商", 'href' => '/search?q=' . $url("{$name} 厂家")],
            ['kw' => "{$name}采购报价", 'href' => '/search?q=' . $url("{$name} 采购报价")],
            ['kw' => $industries[0] . ' ' . $cities[0], 'href' => '/search?q=' . $url($industries[0] . ' ' . $cities[0])],
            ['kw' => $industries[1] . ' ' . $cities[1], 'href' => '/search?q=' . $url($industries[1] . ' ' . $cities[1])],
        ];
        $relatedQueriesHtml = '';
        foreach ($relatedQueries as $rq) {
            $relatedQueriesHtml .= '<a class="geo-query-link" href="' . $h($rq['href']) . '">' . $h($rq['kw']) . '</a>';
        }

        $headerInc = ROOT_PATH . '/includes/header.php';
        $footerInc = ROOT_PATH . '/includes/footer.php';
        ob_start();
        $_SERVER['REQUEST_URI'] = '/topics/geo/' . $slug . '.html';
        include $headerInc;
        $headerHtml = (string)ob_get_clean();
        ob_start();
        include $footerInc;
        $footerHtml = (string)ob_get_clean();

        // header/footer 在静态文件中需要外联完整 doc 包裹；guonika 的静态 hub 都是直接拼 doctype 而非 include。
        // 因此这里手写完整 doc，css 沿用 retro2013.css + 嵌入式 .geo-* 风格

        $css = <<<CSS
body{margin:0;background:#f4f8fc;color:#142238;font-family:"PingFang SC","Microsoft YaHei",sans-serif}
.topic-static-shell{min-height:calc(100vh - 240px);background:radial-gradient(circle at top left,rgba(24,144,255,.07),transparent 24%),linear-gradient(180deg,#f5f8fc 0%,#ffffff 100%);padding-bottom:28px}
.geo-hub-wrap{padding-top:18px}
.geo-hub-hero{margin:20px 0;border-radius:24px;background:linear-gradient(125deg,#173451 0%,#245e92 100%);color:#fff;padding:30px;box-shadow:0 14px 30px rgba(15,39,64,.18)}
.geo-hub-hero h1{margin:0 0 10px;font-size:34px}
.geo-hub-hero p{margin:0;color:rgba(255,255,255,.92);line-height:1.82}
.geo-hub-metrics{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.geo-hub-metrics span{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.15);font-size:13px}
.geo-hub-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.geo-hub-chip{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;font-size:12px}
.geo-section-card{background:#fff;border:1px solid #e6edf6;border-radius:18px;padding:20px;box-shadow:0 6px 16px rgba(15,39,64,.05);margin-top:14px}
.geo-section-card h2{margin:0 0 12px;font-size:21px;color:#173451}
.geo-section-card h3{margin:14px 0 6px;font-size:15px;color:#1f3a63;font-weight:600}
.geo-section-card h3:first-of-type{margin-top:4px}
.geo-section-card p{margin:0 0 8px;line-height:1.85;color:#33445a;font-size:14px}
.geo-focus-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.geo-focus-item{border:1px solid #e5edf6;border-radius:16px;padding:16px;background:#fff}
.geo-focus-item strong{display:block;margin-bottom:6px;color:#173451;font-size:15px}
.geo-focus-item p{margin:0;color:#607186;line-height:1.7;font-size:13px}
.geo-query-list{display:flex;flex-wrap:wrap;gap:8px}
.geo-query-link{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#eef5fb;color:#245e92;text-decoration:none;font-size:12px}
.geo-query-link:hover{background:#dceaf6}
.geo-checklist{margin:0;padding-left:18px;color:#43576d}
.geo-checklist li{margin-bottom:10px;line-height:1.75}
.geo-mini-list{display:flex;flex-direction:column;gap:6px}
.geo-mini-list a{color:#245e92;text-decoration:none;font-size:14px;padding:6px 8px;border-radius:8px}
.geo-mini-list a:hover{background:#eef5fb}
.geo-back-link{display:inline-block;margin-top:18px;color:#245e92;text-decoration:none;font-size:13px}
.geo-back-link:hover{text-decoration:underline}
.container{max-width:1200px;margin:0 auto;padding:0 18px}
.row{display:grid;grid-template-columns:1fr;gap:14px}
@media(min-width:992px){.row.geo-row-2{grid-template-columns:2fr 1fr}}
.footer-link-row{margin-top:24px;padding:18px;background:#fff;border-radius:14px;border:1px solid #e6edf6}
.footer-link-row a{color:#245e92;text-decoration:none;margin-right:14px;font-size:13px;line-height:2.2;display:inline-block}
.footer-link-row a:hover{text-decoration:underline}
CSS;

        $faqSchemaTag = $faqSchema !== '' ? '<script type="application/ld+json">' . $faqSchema . '</script>' : '';
        $crumbsSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '工业专题导航', 'item' => 'https://guonika.com/topics/index.html'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => '区域采购导航', 'item' => 'https://guonika.com/topics/geo/index.html'],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $name . '工业采购导航', 'item' => $canonical],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $kwH = $h($kw);
        $titleH = $h($title);
        $descH = $h($desc);
        $nameH = $h($name);
        $regionH = $h($region);
        $introH = $h($intro);

        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleH} - 全球工业产业链</title>
<meta name="description" content="{$descH}">
<meta name="keywords" content="{$kwH}">
<link rel="canonical" href="{$canonical}">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1779450932">
<link rel="icon" href="/favicon.ico">
<style>{$css}</style>
<script type="application/ld+json">{$crumbsSchema}</script>
{$faqSchemaTag}
</head>
<body>
HTML;
        $html .= "\n" . $headerHtml . "\n";
        $html .= <<<HTML
<main class="main-content topic-static-shell">
<div class="container geo-hub-wrap">
  <section class="geo-hub-hero">
    <div class="mb-2" style="font-size:13px;opacity:.92"><a href="/" style="color:#fff;text-decoration:none">首页</a> / <a href="/topics/index.html" style="color:#fff;text-decoration:none">工业专题导航</a> / <a href="/topics/geo/index.html" style="color:#fff;text-decoration:none">区域采购导航</a> / {$nameH}工业采购导航</div>
    <h1>{$nameH}工业采购导航</h1>
    <p>{$introH}</p>
    <div class="geo-hub-metrics">
      <span><strong>{$regionH}</strong> 区域定位</span>
      <span><strong>5</strong> 类重点行业</span>
      <span><strong>5</strong> 个产业带城市</span>
    </div>
    <div class="geo-hub-chips">{$chips}</div>
  </section>

  <div class="row geo-row-2">
    <div>
      <div class="geo-section-card">
        <h2>{$nameH}重点采购方向</h2>
        <div class="geo-focus-grid">{$focusHtml}</div>
      </div>

      <div class="geo-section-card">
        <h2>区域采购协同清单</h2>
        <ul class="geo-checklist">{$checklistHtml}</ul>
      </div>

      <div class="geo-section-card">
        <h2>推荐检索方向</h2>
        <div class="geo-query-list">{$relatedQueriesHtml}</div>
      </div>

      <div class="geo-section-card">
        <h2>常见问题</h2>
        {$faqHtml}
      </div>
    </div>

    <aside>
      <div class="geo-section-card">
        <h2>主要产业带城市</h2>
        <div class="geo-mini-list">{$cityListHtml}</div>
      </div>
      <div class="geo-section-card">
        <h2>站内导航</h2>
        <div class="geo-mini-list">
          <a href="/topics/geo/index.html">区域采购导航</a>
          <a href="/topics/index.html">工业专题导航</a>
          <a href="/products">产品目录</a>
          <a href="/companies">厂家目录</a>
          <a href="/topics/qa/index.html">专题问答</a>
        </div>
      </div>
    </aside>
  </div>

  <a class="geo-back-link" href="/topics/geo/index.html">← 返回区域采购导航</a>
</div>
</main>
HTML;
        $html .= "\n" . $footerHtml . "\n";
        $html .= "</body>\n</html>\n";
        return $html;
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        $path = ROOT_PATH . self::LOG_FILE;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $line, FILE_APPEND);
        fwrite(STDERR, $line);
    }
}

// CLI
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $arg, $m)) $opts[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $arg, $m)) $opts[$m[1]] = true;
}
(new GeoPageBuilder($opts))->run();
