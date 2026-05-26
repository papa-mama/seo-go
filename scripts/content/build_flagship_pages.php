#!/usr/bin/env php
<?php
/**
 * 旗舰内容静态页生成器（30 并发）
 * 写入 /topics/flagship/*.html，绕过 posts 表（DB 1114 阻塞下仍可执行）
 * 每篇：1500+ 字 / Article schema / 行业 H2 结构 / 真实参数表 / 询价模板 / 内链
 *
 * 用法：
 *   php scripts/build_flagship_pages.php --concurrency=30 --limit=30
 *   php scripts/build_flagship_pages.php --concurrency=10 --limit=5 --dry-run
 *   php scripts/build_flagship_pages.php --topic=ball-valve --force
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/llm_prompt_kit.php';

final class FlagshipPagesBuilder
{
    private const OUT_DIR = '/topics/flagship';
    private const LOG_FILE = '/runtime/build_flagship_pages.log';

    /**
     * 30 个行业旗舰主题：slug => [title, keyword, focus]
     * 选词：覆盖 30 大工业行业 + 高商业意图（采购/选型/价格/厂家）
     */
    private const TOPICS = [
        'ball-valve' => ['title' => '球阀采购选型与厂家询价指南：DN/PN/材质与交期对照', 'keyword' => '球阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'butterfly-valve' => ['title' => '蝶阀选型与询价手册：通径口径、材质、对夹法兰与交期对比', 'keyword' => '蝶阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'centrifugal-pump' => ['title' => '离心泵选型与采购询价：流量扬程匹配、材质与厂家比选', 'keyword' => '离心泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'submersible-pump' => ['title' => '潜水泵采购选型与厂家询价：扬程、流量、材质与场景适配', 'keyword' => '潜水泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'screw-compressor' => ['title' => '螺杆空压机选型采购指南：排气压力、流量、能效与品牌对比', 'keyword' => '螺杆空压机', 'industry' => '空压机', 'category' => '机械设备'],
        'three-phase-motor' => ['title' => '三相异步电机采购询价：功率、极数、防护等级与厂家比选', 'keyword' => '三相异步电机', 'industry' => '电机减速机', 'category' => '电子电工'],
        'gear-reducer' => ['title' => '齿轮减速机选型手册：速比、扭矩、安装方式与采购清单', 'keyword' => '齿轮减速机', 'industry' => '电机减速机', 'category' => '机械设备'],
        'deep-groove-bearing' => ['title' => '深沟球轴承采购选型：型号代号、精度等级、品牌与询价', 'keyword' => '深沟球轴承', 'industry' => '轴承', 'category' => '机械设备'],
        'stainless-bolt' => ['title' => '不锈钢螺栓采购选型：等级标号、材质 304/316、长度与厂家', 'keyword' => '不锈钢螺栓', 'industry' => '紧固件', 'category' => '金属材料'],
        'stainless-plate-304' => ['title' => '304 不锈钢板采购询价：厚度、表面、规格、加工与价格表', 'keyword' => '304不锈钢板', 'industry' => '不锈钢', 'category' => '金属材料'],
        'rebar-hrb400' => ['title' => 'HRB400 螺纹钢采购指南：直径规格、价格走势、厂家与运输', 'keyword' => 'HRB400螺纹钢', 'industry' => '建筑钢材', 'category' => '金属材料'],
        'cold-rolled-coil' => ['title' => '冷轧板卷采购选型：SPCC/DC01、厚度、规格与价格区间', 'keyword' => '冷轧板卷', 'industry' => '钢板', 'category' => '金属材料'],
        'yjv-cable' => ['title' => 'YJV 电力电缆采购选型：截面积、芯数、电压等级与厂家比选', 'keyword' => 'YJV电力电缆', 'industry' => '电缆', 'category' => '电子电工'],
        'cnc-vertical-machining-center' => ['title' => '立式加工中心选型采购：行程、主轴、刀具数量与厂家对比', 'keyword' => '立式加工中心', 'industry' => '数控机床', 'category' => '机械设备'],
        'six-axis-robot' => ['title' => '六轴工业机器人采购选型：负载、臂展、重复定位精度与品牌', 'keyword' => '六轴工业机器人', 'industry' => '工业机器人', 'category' => '机械设备'],
        'pressure-transmitter' => ['title' => '压力变送器采购选型：量程、精度、输出信号、防护与厂家', 'keyword' => '压力变送器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'electromagnetic-flowmeter' => ['title' => '电磁流量计选型与询价：口径、精度、衬里、电极与品牌对比', 'keyword' => '电磁流量计', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'naoh-caustic' => ['title' => '工业级液碱（烧碱）采购选型：浓度、储运要求、价格与厂家', 'keyword' => '液碱', 'industry' => '基础化工', 'category' => '化工及能源'],
        'epoxy-anticorrosion-paint' => ['title' => '环氧防腐涂料采购选型：底漆面漆、用量、施工与厂家比选', 'keyword' => '环氧防腐涂料', 'industry' => '工业涂料', 'category' => '化工及能源'],
        'nbr-rubber-sheet' => ['title' => '丁腈橡胶板采购选型：硬度、厚度、规格、用途与厂家', 'keyword' => '丁腈橡胶板', 'industry' => '工业橡胶', 'category' => '化工及能源'],
        'mechanical-seal' => ['title' => '机械密封采购选型：型号代号、材质组合、用途与厂家比选', 'keyword' => '机械密封', 'industry' => '密封件', 'category' => '机械设备'],
        'gear-oil-220' => ['title' => '工业齿轮油采购选型：粘度等级、添加剂、规格与厂家', 'keyword' => '工业齿轮油', 'industry' => '工业润滑', 'category' => '化工及能源'],
        'safety-shoes' => ['title' => '劳保安全鞋采购选型：防砸防穿刺、ASTM/EN 标准与厂家', 'keyword' => '劳保安全鞋', 'industry' => '劳保用品', 'category' => '应用'],
        'shrink-packaging' => ['title' => '热收缩包装机采购选型：膜宽、速度、自动化与厂家比选', 'keyword' => '热收缩包装机', 'industry' => '包装机械', 'category' => '机械设备'],
        'lifepo4-battery-pack' => ['title' => '磷酸铁锂电池包采购选型：容量、循环寿命、BMS 与厂家', 'keyword' => '磷酸铁锂电池包', 'industry' => '工业电池', 'category' => '电子电工'],
        'led-flood-light' => ['title' => 'LED 工矿灯采购选型：功率、显色指数、防护、安装与厂家', 'keyword' => 'LED工矿灯', 'industry' => '工业照明', 'category' => '电子电工'],
        'ro-membrane' => ['title' => '反渗透膜元件采购选型：型号、产水量、脱盐率与品牌', 'keyword' => '反渗透膜', 'industry' => '水处理设备', 'category' => '化工及能源'],
        'industrial-chiller' => ['title' => '工业冷水机选型采购：制冷量、冷媒、风冷水冷与厂家', 'keyword' => '工业冷水机', 'industry' => '工业暖通', 'category' => '机械设备'],
        'cargo-elevator' => ['title' => '工业货梯选型采购：载重、提升高度、控制方式与厂家', 'keyword' => '工业货梯', 'industry' => '升降设备', 'category' => '机械设备'],
        'forklift-electric' => ['title' => '电动叉车采购选型：载重、举升、电池续航与厂家比选', 'keyword' => '电动叉车', 'industry' => '仓储物流设备', 'category' => '物流'],
        // === 第二批 70 个旗舰主题（2026-05-24 扩） ===
        // 阀门管件类
        'gate-valve' => ['title' => '闸阀采购选型与厂家询价：DN/PN/材质、明杆暗杆与交期对比', 'keyword' => '闸阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'check-valve' => ['title' => '止回阀选型与询价手册：旋启式、升降式、材质与防水击', 'keyword' => '止回阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'globe-valve' => ['title' => '截止阀采购选型：通径、压力等级、材质与法兰对夹对比', 'keyword' => '截止阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'pneumatic-ball-valve' => ['title' => '气动球阀采购选型：执行器扭矩、阀体材质、定位器与厂家', 'keyword' => '气动球阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'electric-actuator-valve' => ['title' => '电动调节阀选型采购：流量特性、CV 值、电动执行器与厂家', 'keyword' => '电动调节阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        // 泵类
        'diaphragm-pump' => ['title' => '气动隔膜泵采购选型：流量、扬程、膜片材质与厂家比选', 'keyword' => '气动隔膜泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'magnetic-drive-pump' => ['title' => '磁力泵选型采购：耐腐蚀、流量扬程、密封与厂家询价', 'keyword' => '磁力泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'self-priming-pump' => ['title' => '自吸泵采购选型：自吸高度、流量扬程、材质与厂家', 'keyword' => '自吸泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'sewage-pump' => ['title' => '污水泵选型采购：颗粒通过、扬程流量、防堵塞与厂家', 'keyword' => '污水泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'metering-pump' => ['title' => '计量泵选型采购：流量精度、压力、调节方式与厂家比选', 'keyword' => '计量泵', 'industry' => '工业泵', 'category' => '机械设备'],
        // 电机类
        'servo-motor' => ['title' => '伺服电机采购选型：扭矩、转速、编码器精度与品牌对比', 'keyword' => '伺服电机', 'industry' => '电机减速机', 'category' => '电子电工'],
        'stepper-motor' => ['title' => '步进电机选型采购：步距角、扭矩、驱动器与厂家比选', 'keyword' => '步进电机', 'industry' => '电机减速机', 'category' => '电子电工'],
        'explosion-proof-motor' => ['title' => '防爆电机采购选型：防爆等级、功率、应用场景与厂家', 'keyword' => '防爆电机', 'industry' => '电机减速机', 'category' => '电子电工'],
        'inverter-motor' => ['title' => '变频电机选型采购：变频范围、绝缘等级、散热与品牌', 'keyword' => '变频电机', 'industry' => '电机减速机', 'category' => '电子电工'],
        'cycloidal-reducer' => ['title' => '摆线针轮减速机采购选型：速比、扭矩、安装与厂家', 'keyword' => '摆线针轮减速机', 'industry' => '电机减速机', 'category' => '机械设备'],
        // 自动化/PLC
        'plc-controller' => ['title' => 'PLC 可编程控制器采购选型：点数、协议、品牌与询价', 'keyword' => 'PLC', 'industry' => '工业自动化', 'category' => '电子电工'],
        'frequency-inverter' => ['title' => '变频器选型与询价：功率、矢量控制、品牌与厂家对比', 'keyword' => '变频器', 'industry' => '工业自动化', 'category' => '电子电工'],
        'hmi-touchscreen' => ['title' => '触摸屏 HMI 采购选型：尺寸、分辨率、协议、品牌与厂家', 'keyword' => '触摸屏HMI', 'industry' => '工业自动化', 'category' => '电子电工'],
        'industrial-switch' => ['title' => '工业以太网交换机选型采购：端口、速率、温度等级与品牌', 'keyword' => '工业以太网交换机', 'industry' => '工业自动化', 'category' => '电子电工'],
        'photoelectric-sensor' => ['title' => '光电传感器采购选型：检测距离、输出方式、品牌与厂家', 'keyword' => '光电传感器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'proximity-sensor' => ['title' => '接近开关选型采购：电感式电容式、距离、输出与厂家', 'keyword' => '接近开关', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'temperature-transmitter' => ['title' => '温度变送器选型采购：量程、精度、信号、热电偶与品牌', 'keyword' => '温度变送器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        // 数控/加工/机器人
        'cnc-horizontal-lathe' => ['title' => '数控卧式车床采购选型：床身规格、主轴、刀塔与厂家', 'keyword' => '数控卧式车床', 'industry' => '数控机床', 'category' => '机械设备'],
        'wire-edm' => ['title' => '电火花线切割采购选型：行程、精度、走丝方式与厂家', 'keyword' => '电火花线切割', 'industry' => '数控机床', 'category' => '机械设备'],
        'laser-cutting-machine' => ['title' => '激光切割机选型采购：功率、幅面、光纤光源与品牌', 'keyword' => '激光切割机', 'industry' => '数控机床', 'category' => '机械设备'],
        'cnc-bending-machine' => ['title' => '数控折弯机采购选型：压力、长度、控制系统与厂家', 'keyword' => '数控折弯机', 'industry' => '数控机床', 'category' => '机械设备'],
        'scara-robot' => ['title' => 'SCARA 工业机器人采购选型：负载、臂展、速度与厂家', 'keyword' => 'SCARA机器人', 'industry' => '工业机器人', 'category' => '机械设备'],
        'collaborative-robot' => ['title' => '协作机器人采购选型：负载、安全等级、品牌与厂家', 'keyword' => '协作机器人', 'industry' => '工业机器人', 'category' => '机械设备'],
        // 紧固件 / 五金
        'stainless-nut' => ['title' => '不锈钢螺母采购选型：等级、材质、规格与厂家询价', 'keyword' => '不锈钢螺母', 'industry' => '紧固件', 'category' => '金属材料'],
        'hex-bolt-grade88' => ['title' => '8.8 级外六角螺栓采购选型：等级、长度、表面处理与厂家', 'keyword' => '高强度螺栓', 'industry' => '紧固件', 'category' => '金属材料'],
        'expansion-anchor' => ['title' => '膨胀螺栓采购选型：规格、材质、拉拔力与厂家', 'keyword' => '膨胀螺栓', 'industry' => '紧固件', 'category' => '金属材料'],
        'flat-washer' => ['title' => '平垫圈采购选型：规格、材质、标准与厂家询价', 'keyword' => '平垫圈', 'industry' => '紧固件', 'category' => '金属材料'],
        // 钢材 / 金属
        'h-beam-steel' => ['title' => 'H 型钢采购选型：规格、Q235/Q345、价格走势与厂家', 'keyword' => 'H型钢', 'industry' => '建筑钢材', 'category' => '金属材料'],
        'galvanized-pipe' => ['title' => '镀锌钢管采购选型：管径、壁厚、镀层、规格与厂家', 'keyword' => '镀锌钢管', 'industry' => '钢管', 'category' => '金属材料'],
        'seamless-steel-pipe' => ['title' => '无缝钢管采购选型：管径壁厚、材质、规格与厂家', 'keyword' => '无缝钢管', 'industry' => '钢管', 'category' => '金属材料'],
        'aluminum-plate-6061' => ['title' => '6061 铝板采购询价：厚度、规格、状态与厂家比选', 'keyword' => '6061铝板', 'industry' => '有色金属', 'category' => '金属材料'],
        'copper-busbar' => ['title' => '铜排采购选型：规格、纯度、表面处理与厂家询价', 'keyword' => '铜排', 'industry' => '有色金属', 'category' => '金属材料'],
        // 电缆 / 电气
        'low-voltage-switchgear' => ['title' => '低压配电柜采购选型：方案、母线、品牌、定制与厂家', 'keyword' => '低压配电柜', 'industry' => '配电设备', 'category' => '电子电工'],
        'circuit-breaker-mccb' => ['title' => 'MCCB 塑壳断路器选型采购：电流、品牌、规格与厂家', 'keyword' => '塑壳断路器', 'industry' => '低压电器', 'category' => '电子电工'],
        'contactor-ac' => ['title' => '交流接触器采购选型：电流、品牌、辅助触点与厂家', 'keyword' => '交流接触器', 'industry' => '低压电器', 'category' => '电子电工'],
        'control-cable' => ['title' => '控制电缆采购选型：芯数、截面、屏蔽、规格与厂家', 'keyword' => '控制电缆', 'industry' => '电缆', 'category' => '电子电工'],
        // 化工 / 涂料 / 橡胶
        'sulfuric-acid' => ['title' => '工业级硫酸采购选型：浓度、纯度、储运与厂家询价', 'keyword' => '工业硫酸', 'industry' => '基础化工', 'category' => '化工及能源'],
        'hydrochloric-acid' => ['title' => '工业盐酸采购选型：浓度、纯度、储运与厂家询价', 'keyword' => '工业盐酸', 'industry' => '基础化工', 'category' => '化工及能源'],
        'polyurethane-paint' => ['title' => '聚氨酯漆采购选型：底漆面漆、固化剂、用量与厂家', 'keyword' => '聚氨酯漆', 'industry' => '工业涂料', 'category' => '化工及能源'],
        'silicone-rubber-sheet' => ['title' => '硅橡胶板采购选型：硬度、耐温、规格与厂家询价', 'keyword' => '硅橡胶板', 'industry' => '工业橡胶', 'category' => '化工及能源'],
        'epdm-rubber' => ['title' => '三元乙丙橡胶（EPDM）采购选型：硬度、规格、性能与厂家', 'keyword' => 'EPDM三元乙丙橡胶', 'industry' => '工业橡胶', 'category' => '化工及能源'],
        // 润滑 / 油脂
        'hydraulic-oil-46' => ['title' => '46 号抗磨液压油采购选型：等级、添加剂、规格与厂家', 'keyword' => '抗磨液压油', 'industry' => '工业润滑', 'category' => '化工及能源'],
        'cutting-fluid' => ['title' => '金属切削液采购选型：水基、油基、配比与厂家', 'keyword' => '切削液', 'industry' => '工业润滑', 'category' => '化工及能源'],
        'lithium-grease' => ['title' => '锂基润滑脂采购选型：稠度、滴点、规格与厂家', 'keyword' => '锂基润滑脂', 'industry' => '工业润滑', 'category' => '化工及能源'],
        // 密封 / 轴承
        'angular-contact-bearing' => ['title' => '角接触球轴承采购选型：型号、精度、配对与品牌', 'keyword' => '角接触球轴承', 'industry' => '轴承', 'category' => '机械设备'],
        'tapered-roller-bearing' => ['title' => '圆锥滚子轴承采购选型：型号、精度、品牌与厂家', 'keyword' => '圆锥滚子轴承', 'industry' => '轴承', 'category' => '机械设备'],
        'o-ring-seal' => ['title' => 'O 型密封圈采购选型：规格、材质、耐温与厂家', 'keyword' => 'O型密封圈', 'industry' => '密封件', 'category' => '机械设备'],
        'oil-seal' => ['title' => '油封采购选型：规格、材质、骨架与厂家询价', 'keyword' => '油封', 'industry' => '密封件', 'category' => '机械设备'],
        // 包装 / 物流
        'pallet-wrapping-machine' => ['title' => '托盘缠绕机采购选型：膜宽、速度、自动化与厂家', 'keyword' => '托盘缠绕机', 'industry' => '包装机械', 'category' => '机械设备'],
        'carton-sealing-machine' => ['title' => '封箱机采购选型：胶带宽度、速度、规格与厂家', 'keyword' => '封箱机', 'industry' => '包装机械', 'category' => '机械设备'],
        'stacker-crane' => ['title' => '堆垛机采购选型：载重、高度、轨道与厂家', 'keyword' => '堆垛机', 'industry' => '仓储物流设备', 'category' => '物流'],
        'agv-forklift' => ['title' => 'AGV 无人叉车采购选型：导航、载重、品牌与厂家', 'keyword' => 'AGV无人叉车', 'industry' => '仓储物流设备', 'category' => '物流'],
        // 暖通 / 制冷 / 水处理
        'cooling-tower' => ['title' => '冷却塔采购选型：流量、温差、风机、品牌与厂家', 'keyword' => '冷却塔', 'industry' => '工业暖通', 'category' => '机械设备'],
        'air-handling-unit' => ['title' => '组合式空调机组采购选型：风量、配置、控制与厂家', 'keyword' => '组合式空调机组', 'industry' => '工业暖通', 'category' => '机械设备'],
        'water-softener' => ['title' => '软化水设备采购选型：流量、再生方式、罐体与厂家', 'keyword' => '软化水设备', 'industry' => '水处理设备', 'category' => '化工及能源'],
        'uv-sterilizer' => ['title' => '紫外线消毒器采购选型：流量、剂量、灯管与厂家', 'keyword' => '紫外线消毒器', 'industry' => '水处理设备', 'category' => '化工及能源'],
        // 电池 / 光伏 / 储能
        'lead-acid-battery' => ['title' => '工业铅酸蓄电池采购选型：容量、循环、品牌与厂家', 'keyword' => '工业铅酸蓄电池', 'industry' => '工业电池', 'category' => '电子电工'],
        'solar-panel' => ['title' => '工业光伏组件采购选型：功率、效率、规格与厂家', 'keyword' => '光伏组件', 'industry' => '光伏', 'category' => '化工及能源'],
        'energy-storage-system' => ['title' => '工商业储能系统采购选型：容量、功率、品牌与厂家', 'keyword' => '工商业储能系统', 'industry' => '工业电池', 'category' => '化工及能源'],
        // 照明 / 安全
        'led-highbay-light' => ['title' => 'LED 高棚灯采购选型：功率、显色、防护、安装与厂家', 'keyword' => 'LED高棚灯', 'industry' => '工业照明', 'category' => '电子电工'],
        'safety-helmet' => ['title' => '安全帽采购选型：标准、材质、电气绝缘与厂家', 'keyword' => '安全帽', 'industry' => '劳保用品', 'category' => '应用'],
        'work-gloves' => ['title' => '工业劳保手套采购选型：材质、防护等级、用途与厂家', 'keyword' => '劳保手套', 'industry' => '劳保用品', 'category' => '应用'],
        // 仪器仪表
        'multimeter-digital' => ['title' => '数字万用表采购选型：精度、量程、品牌与厂家', 'keyword' => '数字万用表', 'industry' => '仪器仪表', 'category' => '电子电工'],
        'oscilloscope-digital' => ['title' => '数字示波器采购选型：带宽、采样率、通道与品牌', 'keyword' => '数字示波器', 'industry' => '仪器仪表', 'category' => '电子电工'],
        'infrared-thermometer' => ['title' => '红外测温仪采购选型：量程、精度、距离系数与品牌', 'keyword' => '红外测温仪', 'industry' => '仪器仪表', 'category' => '电子电工'],
        // === 第三批 100 个旗舰主题（2026-05-24 二轮扩） ===
        // 纺织 / 工业布料
        'polyester-fabric' => ['title' => '涤纶工业面料采购选型：克重、规格、用途与厂家', 'keyword' => '涤纶工业面料', 'industry' => '工业纺织', 'category' => '应用'],
        'cotton-yarn' => ['title' => '工业棉纱采购选型：支数、配棉、规格与厂家询价', 'keyword' => '工业棉纱', 'industry' => '工业纺织', 'category' => '应用'],
        'nonwoven-fabric' => ['title' => '无纺布采购选型：克重、工艺、规格与厂家比选', 'keyword' => '无纺布', 'industry' => '工业纺织', 'category' => '应用'],
        'industrial-felt' => ['title' => '工业毛毡采购选型：厚度、密度、规格与厂家', 'keyword' => '工业毛毡', 'industry' => '工业纺织', 'category' => '应用'],
        'fiberglass-cloth' => ['title' => '玻璃纤维布采购选型：克重、织法、规格与厂家', 'keyword' => '玻璃纤维布', 'industry' => '工业纺织', 'category' => '化工及能源'],
        // 玻璃
        'tempered-glass' => ['title' => '钢化玻璃采购选型：厚度、规格、加工与厂家询价', 'keyword' => '钢化玻璃', 'industry' => '工业玻璃', 'category' => '金属材料'],
        'laminated-glass' => ['title' => '夹层玻璃采购选型：膜层、厚度、规格与厂家', 'keyword' => '夹层玻璃', 'industry' => '工业玻璃', 'category' => '金属材料'],
        'float-glass' => ['title' => '浮法玻璃采购选型：厚度、白度、规格与厂家', 'keyword' => '浮法玻璃', 'industry' => '工业玻璃', 'category' => '金属材料'],
        'insulated-glazing' => ['title' => '中空玻璃采购选型：厚度、间隔条、规格与厂家', 'keyword' => '中空玻璃', 'industry' => '工业玻璃', 'category' => '金属材料'],
        // 特种钢 / 合金
        'tool-steel-h13' => ['title' => 'H13 模具钢采购选型：硬度、规格、热处理与厂家', 'keyword' => 'H13模具钢', 'industry' => '模具钢', 'category' => '金属材料'],
        'die-steel-skd11' => ['title' => 'SKD11 模具钢采购选型：硬度、规格、加工与厂家', 'keyword' => 'SKD11模具钢', 'industry' => '模具钢', 'category' => '金属材料'],
        'alloy-steel-42crmo' => ['title' => '42CrMo 合金钢采购选型：硬度、规格、热处理与厂家', 'keyword' => '42CrMo合金钢', 'industry' => '合金钢', 'category' => '金属材料'],
        'spring-steel' => ['title' => '弹簧钢 65Mn 采购选型：硬度、规格、用途与厂家', 'keyword' => '弹簧钢', 'industry' => '合金钢', 'category' => '金属材料'],
        'tungsten-carbide' => ['title' => '硬质合金采购选型：硬度、规格、刀片刀具与厂家', 'keyword' => '硬质合金', 'industry' => '合金材料', 'category' => '金属材料'],
        'titanium-alloy' => ['title' => '钛合金 TC4 采购选型：规格、性能、热处理与厂家', 'keyword' => '钛合金', 'industry' => '有色金属', 'category' => '金属材料'],
        // 检测 / 计量仪器
        'vernier-caliper' => ['title' => '数显游标卡尺采购选型：量程、精度、品牌与厂家', 'keyword' => '数显游标卡尺', 'industry' => '量具检测', 'category' => '电子电工'],
        'micrometer-digital' => ['title' => '数显千分尺采购选型：量程、精度、品牌与厂家', 'keyword' => '数显千分尺', 'industry' => '量具检测', 'category' => '电子电工'],
        'coordinate-measuring-machine' => ['title' => '三坐标测量机采购选型：行程、精度、品牌与厂家', 'keyword' => '三坐标测量机', 'industry' => '量具检测', 'category' => '电子电工'],
        'hardness-tester' => ['title' => '硬度计采购选型：洛氏、布氏、维氏与品牌', 'keyword' => '硬度计', 'industry' => '量具检测', 'category' => '电子电工'],
        'surface-roughness-tester' => ['title' => '表面粗糙度仪采购选型：精度、参数、品牌与厂家', 'keyword' => '表面粗糙度仪', 'industry' => '量具检测', 'category' => '电子电工'],
        'ultrasonic-flaw-detector' => ['title' => '超声波探伤仪采购选型：频率、探头、品牌与厂家', 'keyword' => '超声波探伤仪', 'industry' => '无损检测', 'category' => '电子电工'],
        // 激光 / 增材制造
        'laser-welding-machine' => ['title' => '激光焊接机采购选型：功率、光纤、应用与厂家', 'keyword' => '激光焊接机', 'industry' => '激光设备', 'category' => '机械设备'],
        'laser-engraving-machine' => ['title' => '激光打标机采购选型：功率、幅面、光纤光源与品牌', 'keyword' => '激光打标机', 'industry' => '激光设备', 'category' => '机械设备'],
        'fdm-3d-printer' => ['title' => 'FDM 工业级 3D 打印机采购选型：成型尺寸、精度与厂家', 'keyword' => 'FDM 3D打印机', 'industry' => '增材制造', 'category' => '机械设备'],
        'metal-3d-printer' => ['title' => '金属 3D 打印机采购选型：SLM、成型尺寸、激光器与厂家', 'keyword' => '金属3D打印机', 'industry' => '增材制造', 'category' => '机械设备'],
        // 传感器扩展
        'encoder-rotary' => ['title' => '旋转编码器采购选型：分辨率、信号、品牌与厂家', 'keyword' => '旋转编码器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'load-cell' => ['title' => '称重传感器采购选型：量程、精度、安装与品牌', 'keyword' => '称重传感器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'flow-switch' => ['title' => '流量开关采购选型：管径、介质、信号与品牌', 'keyword' => '流量开关', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        'limit-switch' => ['title' => '行程开关采购选型：触点、防护、品牌与厂家', 'keyword' => '行程开关', 'industry' => '低压电器', 'category' => '电子电工'],
        'level-sensor' => ['title' => '液位传感器采购选型：原理、量程、信号与品牌', 'keyword' => '液位传感器', 'industry' => '传感器与仪表', 'category' => '电子电工'],
        // 泵扩展
        'screw-pump' => ['title' => '螺杆泵采购选型：流量、压力、介质与厂家比选', 'keyword' => '螺杆泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'gear-pump' => ['title' => '齿轮泵采购选型：流量、压力、材质与厂家', 'keyword' => '齿轮泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'vacuum-pump' => ['title' => '真空泵采购选型：抽气量、极限真空、品牌与厂家', 'keyword' => '真空泵', 'industry' => '工业泵', 'category' => '机械设备'],
        'peristaltic-pump' => ['title' => '蠕动泵采购选型：流量、软管、精度与品牌', 'keyword' => '蠕动泵', 'industry' => '工业泵', 'category' => '机械设备'],
        // 阀门扩展
        'solenoid-valve' => ['title' => '电磁阀采购选型：通径、压力、电压、介质与厂家', 'keyword' => '电磁阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'needle-valve' => ['title' => '针型阀采购选型：通径、压力、材质与厂家', 'keyword' => '针型阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        'plug-valve' => ['title' => '旋塞阀采购选型：通径、压力等级、材质与厂家', 'keyword' => '旋塞阀', 'industry' => '阀门管件', 'category' => '机械设备'],
        // 轴承扩展
        'cylindrical-roller-bearing' => ['title' => '圆柱滚子轴承采购选型：型号、精度、品牌与厂家', 'keyword' => '圆柱滚子轴承', 'industry' => '轴承', 'category' => '机械设备'],
        'spherical-roller-bearing' => ['title' => '调心滚子轴承采购选型：型号、精度、品牌与厂家', 'keyword' => '调心滚子轴承', 'industry' => '轴承', 'category' => '机械设备'],
        'linear-bearing' => ['title' => '直线轴承采购选型：型号、行程、品牌与厂家', 'keyword' => '直线轴承', 'industry' => '轴承', 'category' => '机械设备'],
        // 紧固件扩展
        'self-tapping-screw' => ['title' => '自攻螺钉采购选型：规格、材质、表面处理与厂家', 'keyword' => '自攻螺钉', 'industry' => '紧固件', 'category' => '金属材料'],
        'rivet-blind' => ['title' => '抽芯铆钉采购选型：规格、材质、拉拔力与厂家', 'keyword' => '抽芯铆钉', 'industry' => '紧固件', 'category' => '金属材料'],
        'spring-lock-washer' => ['title' => '弹簧垫圈采购选型：规格、材质、标准与厂家', 'keyword' => '弹簧垫圈', 'industry' => '紧固件', 'category' => '金属材料'],
        // 钢材扩展
        'carbon-steel-plate' => ['title' => '碳钢板采购选型：Q235B、厚度、规格与厂家', 'keyword' => '碳钢板', 'industry' => '钢板', 'category' => '金属材料'],
        'stainless-steel-pipe' => ['title' => '不锈钢管采购选型：304/316、规格、壁厚与厂家', 'keyword' => '不锈钢管', 'industry' => '不锈钢', 'category' => '金属材料'],
        'q345-steel-plate' => ['title' => 'Q345 低合金钢板采购选型：厚度、规格、强度与厂家', 'keyword' => 'Q345钢板', 'industry' => '钢板', 'category' => '金属材料'],
        'weather-resistant-steel' => ['title' => '耐候钢板 Q355NH 采购选型：规格、性能与厂家', 'keyword' => '耐候钢板', 'industry' => '钢板', 'category' => '金属材料'],
        'checker-plate' => ['title' => '花纹钢板采购选型：厚度、花纹、规格与厂家', 'keyword' => '花纹钢板', 'industry' => '钢板', 'category' => '金属材料'],
        // 有色金属扩展
        'brass-rod' => ['title' => '黄铜棒采购选型：H59/H62、规格、状态与厂家', 'keyword' => '黄铜棒', 'industry' => '有色金属', 'category' => '金属材料'],
        'aluminum-profile-6063' => ['title' => '6063 铝型材采购选型：规格、表面处理、定制与厂家', 'keyword' => '6063铝型材', 'industry' => '有色金属', 'category' => '金属材料'],
        'copper-pipe' => ['title' => '紫铜管采购选型：规格、壁厚、状态与厂家', 'keyword' => '紫铜管', 'industry' => '有色金属', 'category' => '金属材料'],
        'zinc-ingot' => ['title' => '锌锭采购选型：纯度、规格、价格走势与厂家', 'keyword' => '锌锭', 'industry' => '有色金属', 'category' => '金属材料'],
        // 电缆扩展
        'fiber-optic-cable' => ['title' => '光纤光缆采购选型：芯数、外护、规格与厂家', 'keyword' => '光纤光缆', 'industry' => '电缆', 'category' => '电子电工'],
        'rubber-cable' => ['title' => '橡套电缆采购选型：YC/YZ、规格、芯数与厂家', 'keyword' => '橡套电缆', 'industry' => '电缆', 'category' => '电子电工'],
        'fire-resistant-cable' => ['title' => '阻燃耐火电缆采购选型：等级、规格、芯数与厂家', 'keyword' => '阻燃耐火电缆', 'industry' => '电缆', 'category' => '电子电工'],
        // 机床扩展
        'cnc-grinding-machine' => ['title' => '数控磨床采购选型：行程、精度、砂轮与厂家', 'keyword' => '数控磨床', 'industry' => '数控机床', 'category' => '机械设备'],
        'universal-milling-machine' => ['title' => '万能铣床采购选型：工作台、行程、规格与厂家', 'keyword' => '万能铣床', 'industry' => '数控机床', 'category' => '机械设备'],
        'radial-drilling-machine' => ['title' => '摇臂钻床采购选型：钻孔直径、行程、规格与厂家', 'keyword' => '摇臂钻床', 'industry' => '数控机床', 'category' => '机械设备'],
        // 机器人 / 视觉
        'agv-mobile-robot' => ['title' => 'AGV 移动机器人采购选型：导航、载重、品牌与厂家', 'keyword' => 'AGV移动机器人', 'industry' => '工业机器人', 'category' => '机械设备'],
        'industrial-camera' => ['title' => '工业相机采购选型：分辨率、接口、镜头与品牌', 'keyword' => '工业相机', 'industry' => '机器视觉', 'category' => '电子电工'],
        'machine-vision-system' => ['title' => '机器视觉系统采购选型：方案、应用、集成与厂家', 'keyword' => '机器视觉系统', 'industry' => '机器视觉', 'category' => '电子电工'],
        // 化工扩展
        'methanol-industrial' => ['title' => '工业甲醇采购选型：纯度、规格、储运与厂家', 'keyword' => '工业甲醇', 'industry' => '基础化工', 'category' => '化工及能源'],
        'ethanol-industrial' => ['title' => '工业乙醇采购选型：纯度、规格、储运与厂家', 'keyword' => '工业乙醇', 'industry' => '基础化工', 'category' => '化工及能源'],
        'industrial-salt' => ['title' => '工业盐采购选型：纯度、规格、用途与厂家', 'keyword' => '工业盐', 'industry' => '基础化工', 'category' => '化工及能源'],
        'activated-carbon' => ['title' => '活性炭采购选型：碘值、规格、用途与厂家', 'keyword' => '活性炭', 'industry' => '基础化工', 'category' => '化工及能源'],
        'industrial-resin' => ['title' => '工业树脂采购选型：环氧、酚醛、规格与厂家', 'keyword' => '工业树脂', 'industry' => '基础化工', 'category' => '化工及能源'],
        // 涂料扩展
        'powder-coating' => ['title' => '粉末涂料采购选型：色号、规格、用量与厂家', 'keyword' => '粉末涂料', 'industry' => '工业涂料', 'category' => '化工及能源'],
        'zinc-rich-primer' => ['title' => '富锌底漆采购选型：含锌量、用量、规格与厂家', 'keyword' => '富锌底漆', 'industry' => '工业涂料', 'category' => '化工及能源'],
        'waterproof-coating' => ['title' => '防水涂料采购选型：类型、用量、规格与厂家', 'keyword' => '防水涂料', 'industry' => '工业涂料', 'category' => '化工及能源'],
        // 工程塑料 / 橡胶扩展
        'ptfe-sheet' => ['title' => 'PTFE 聚四氟乙烯板采购选型：厚度、规格、性能与厂家', 'keyword' => 'PTFE板', 'industry' => '工程塑料', 'category' => '化工及能源'],
        'nylon-66-sheet' => ['title' => '尼龙板（PA66）采购选型：厚度、规格、性能与厂家', 'keyword' => '尼龙板', 'industry' => '工程塑料', 'category' => '化工及能源'],
        'abs-plastic-sheet' => ['title' => 'ABS 塑料板采购选型：厚度、规格、颜色与厂家', 'keyword' => 'ABS塑料板', 'industry' => '工程塑料', 'category' => '化工及能源'],
        // 密封 / 传动扩展
        'graphite-gasket' => ['title' => '石墨垫片采购选型：规格、增强、压力与厂家', 'keyword' => '石墨垫片', 'industry' => '密封件', 'category' => '机械设备'],
        'ptfe-packing' => ['title' => '四氟盘根采购选型：规格、耐温、压力与厂家', 'keyword' => '四氟盘根', 'industry' => '密封件', 'category' => '机械设备'],
        'v-belt-industrial' => ['title' => '工业三角带采购选型：型号、长度、品牌与厂家', 'keyword' => '工业三角带', 'industry' => '传动件', 'category' => '机械设备'],
        // 润滑扩展
        'compressor-oil' => ['title' => '空压机油采购选型：粘度、添加剂、规格与厂家', 'keyword' => '空压机油', 'industry' => '工业润滑', 'category' => '化工及能源'],
        'heat-transfer-oil' => ['title' => '导热油采购选型：型号、耐温、规格与厂家', 'keyword' => '导热油', 'industry' => '工业润滑', 'category' => '化工及能源'],
        // 包装扩展
        'filling-machine' => ['title' => '灌装机采购选型：容量、速度、规格与厂家', 'keyword' => '灌装机', 'industry' => '包装机械', 'category' => '机械设备'],
        'labeling-machine' => ['title' => '贴标机采购选型：速度、精度、规格与厂家', 'keyword' => '贴标机', 'industry' => '包装机械', 'category' => '机械设备'],
        // 电池 / 新能源扩展
        'ni-mh-battery' => ['title' => '镍氢电池采购选型：容量、循环、规格与厂家', 'keyword' => '镍氢电池', 'industry' => '工业电池', 'category' => '电子电工'],
        'solar-inverter' => ['title' => '光伏逆变器采购选型：功率、效率、品牌与厂家', 'keyword' => '光伏逆变器', 'industry' => '光伏', 'category' => '化工及能源'],
        'ev-charger' => ['title' => '工业充电桩采购选型：功率、接口、品牌与厂家', 'keyword' => '工业充电桩', 'industry' => '工业电池', 'category' => '化工及能源'],
        // 照明扩展
        'explosion-proof-lamp' => ['title' => '防爆灯采购选型：防爆等级、功率、规格与厂家', 'keyword' => '防爆灯', 'industry' => '工业照明', 'category' => '电子电工'],
        'solar-street-light' => ['title' => '太阳能路灯采购选型：功率、亮度、电池与厂家', 'keyword' => '太阳能路灯', 'industry' => '工业照明', 'category' => '电子电工'],
        // 劳保扩展
        'anti-cut-gloves' => ['title' => '防割手套采购选型：等级、材质、规格与厂家', 'keyword' => '防割手套', 'industry' => '劳保用品', 'category' => '应用'],
        'dust-mask' => ['title' => '工业防尘口罩采购选型：KN95、防护等级与厂家', 'keyword' => '工业防尘口罩', 'industry' => '劳保用品', 'category' => '应用'],
        'ear-protection' => ['title' => '防噪耳塞耳罩采购选型：降噪量、舒适度与厂家', 'keyword' => '防噪耳塞', 'industry' => '劳保用品', 'category' => '应用'],
        // 暖通扩展
        'fan-coil-unit' => ['title' => '风机盘管采购选型：风量、冷量、规格与厂家', 'keyword' => '风机盘管', 'industry' => '工业暖通', 'category' => '机械设备'],
        'air-cooler-evaporative' => ['title' => '蒸发式冷气机采购选型：风量、覆盖面积与厂家', 'keyword' => '蒸发式冷气机', 'industry' => '工业暖通', 'category' => '机械设备'],
        // 水处理扩展
        'activated-carbon-filter' => ['title' => '活性炭过滤器采购选型：流量、罐体、规格与厂家', 'keyword' => '活性炭过滤器', 'industry' => '水处理设备', 'category' => '化工及能源'],
        'mbr-membrane' => ['title' => 'MBR 膜采购选型：通量、规格、品牌与厂家', 'keyword' => 'MBR膜', 'industry' => '水处理设备', 'category' => '化工及能源'],
        // 焊接 / 切割
        'mig-welding-machine' => ['title' => 'MIG 焊机采购选型：电流、占空比、品牌与厂家', 'keyword' => 'MIG焊机', 'industry' => '焊接设备', 'category' => '机械设备'],
        'tig-welding-machine' => ['title' => 'TIG 焊机采购选型：电流、脉冲、品牌与厂家', 'keyword' => 'TIG焊机', 'industry' => '焊接设备', 'category' => '机械设备'],
        'welding-wire' => ['title' => '焊丝采购选型：实芯药芯、规格、品牌与厂家', 'keyword' => '焊丝', 'industry' => '焊接材料', 'category' => '金属材料'],
        'welding-electrode' => ['title' => '焊条采购选型：E4303、E5015、规格与厂家', 'keyword' => '焊条', 'industry' => '焊接材料', 'category' => '金属材料'],
        // 热处理 / 工业炉
        'industrial-furnace' => ['title' => '工业电炉采购选型：温度、容积、应用与厂家', 'keyword' => '工业电炉', 'industry' => '热处理设备', 'category' => '机械设备'],
        'heat-treatment-equipment' => ['title' => '热处理设备采购选型：方案、温度、规格与厂家', 'keyword' => '热处理设备', 'industry' => '热处理设备', 'category' => '机械设备'],
        // 工程机械
        'tower-crane' => ['title' => '塔吊采购选型：型号、起重量、臂长与厂家', 'keyword' => '塔吊', 'industry' => '工程机械', 'category' => '机械设备'],
        'concrete-mixer' => ['title' => '混凝土搅拌机采购选型：容量、规格、品牌与厂家', 'keyword' => '混凝土搅拌机', 'industry' => '工程机械', 'category' => '机械设备'],
        'mini-excavator' => ['title' => '小型挖掘机采购选型：吨位、品牌、规格与厂家', 'keyword' => '小型挖掘机', 'industry' => '工程机械', 'category' => '机械设备'],
    ];

    private string $apiUrl;
    private string $apiKey;
    private string $model;
    private int $limit;
    private int $concurrency;
    private bool $dryRun;
    private bool $force;
    private ?string $onlyTopic;

    public function __construct(array $opts)
    {
        $this->apiUrl = rtrim(LLM_API_URL, '/') . '/chat/completions';
        $this->apiKey = LLM_API_KEY;
        $this->model = LLM_MODEL;
        $this->limit = max(1, (int)($opts['limit'] ?? 30));
        $this->concurrency = max(1, min(50, (int)($opts['concurrency'] ?? 30)));
        $this->dryRun = !empty($opts['dry-run']);
        $this->force = !empty($opts['force']);
        $this->onlyTopic = isset($opts['topic']) ? (string)$opts['topic'] : null;
    }

    public function run(): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

        $candidates = self::TOPICS;
        if ($this->onlyTopic) {
            $candidates = isset($candidates[$this->onlyTopic]) ? [$this->onlyTopic => $candidates[$this->onlyTopic]] : [];
        }

        // 跳过已存在（除非 --force）
        $jobs = [];
        foreach ($candidates as $slug => $meta) {
            $path = $outDir . '/' . $slug . '.html';
            if (!$this->force && is_file($path) && filesize($path) > 4000) continue;
            $jobs[$slug] = $meta;
            if (count($jobs) >= $this->limit) break;
        }
        $this->log(sprintf('jobs=%d concurrency=%d dry=%s force=%s', count($jobs), $this->concurrency, $this->dryRun ? 'yes' : 'no', $this->force ? 'yes' : 'no'));

        if (!$jobs) { $this->log('nothing to do'); return; }

        $stats = ['ok' => 0, 'fail_http' => 0, 'fail_parse' => 0, 'fail_short' => 0];
        $batches = array_chunk($jobs, $this->concurrency, true);
        foreach ($batches as $batchIdx => $batch) {
            $t0 = microtime(true);
            $results = $this->callBatch($batch);
            foreach ($results as $slug => $resp) {
                try {
                    $meta = $batch[$slug];
                    if ($resp['err'] !== '') {
                        $stats['fail_http']++;
                        $this->log("FAIL HTTP slug=$slug err={$resp['err']}");
                        continue;
                    }
                    $parsed = $this->parseResponse((string)$resp['body']);
                    if (!is_array($parsed)) {
                        $stats['fail_parse']++;
                        $this->log("FAIL PARSE slug=$slug rawlen=" . strlen((string)$resp['body']));
                        continue;
                    }
                    $bodyText = implode('', $parsed['sections'] ?? []);
                    if (mb_strlen($bodyText, 'UTF-8') < 1300) {
                        $stats['fail_short']++;
                        $this->log("FAIL SHORT slug=$slug len=" . mb_strlen($bodyText, 'UTF-8'));
                        continue;
                    }
                    if ($this->dryRun) {
                        $this->log("DRY slug=$slug title={$parsed['title']} len=" . mb_strlen($bodyText, 'UTF-8'));
                    } else {
                        $html = $this->renderHtml($slug, $meta, $parsed);
                        $path = ROOT_PATH . self::OUT_DIR . '/' . $slug . '.html';
                        file_put_contents($path, $html);
                        $this->log("OK slug=$slug bytes=" . strlen($html));
                    }
                    $stats['ok']++;
                } catch (\Throwable $e) {
                    $this->log("FAIL EXCEPTION slug=$slug err=" . $e->getMessage());
                }
            }
            $dt = round((microtime(true) - $t0) * 1000);
            $this->log(sprintf('batch %d done in %dms', $batchIdx + 1, $dt));
        }
        $this->log('STATS ' . json_encode($stats, JSON_UNESCAPED_UNICODE));

        if (!$this->dryRun && $stats['ok'] > 0) {
            $this->writeIndex();
        }
    }

    private function buildPrompt(string $slug, array $meta): string
    {
        $title = $meta['title'];
        $keyword = $meta['keyword'];
        $industry = $meta['industry'];
        $rules = LLMPromptKit::rules([
            'reader_persona', 'anti_ai_slop', 'industrial_quantify',
            'industrial_voice', 'rfq_oriented', 'no_fabrication',
            'writing_density', 'no_markdown',
        ]);
        $checklist = LLMPromptKit::checklist('flagship');
        return <<<PROMPT
{$rules}

【本次任务】围绕"{$keyword}"撰写一篇旗舰采购指南，发布在产品目录站旗下的旗舰专题页。
主题（可改写但保持核心意图）：{$title}
归属行业：{$industry}

写作要求：
1. 总字数 1800-2400 字之间，给采购方一份能直接拿去对比与询价的实用文档。
2. 必须按下列六个章节组织内容（顺序与名称都要保留），每个章节 280-420 字：
   - 一、用途与典型工况：写明 {$keyword} 在什么场景下使用，以及 3-5 个常见工况组合（介质 / 温度 / 压力 / 流量 / 材质 / 输出信号 / 工作介质等可量化字段）。
   - 二、规格与选型要点：列出选型时必须确认的关键参数（含 GB/JB/ISO 等标准号若适用），以及 4-6 个典型规格档位（要写真实的口径 / 功率 / 容量 / 精度 / 材质 / 表面工艺 / 标号 / 厚度等数值）。
   - 三、询价单与采购清单：写出一份能直接发给厂家的询价信息清单，包括"基础参数 / 数量 / 交期 / 包装 / 物流 / 验收 / 售后"七大块，让读者复制即可使用。
   - 四、品牌厂家与产业带：写明国内主要供应商分布与产业带（华东/华南/华北/西南至少各一区域），以及厂家比选时常用的资质与硬指标（如 ISO9001、3C、防爆、行业认证等）。
   - 五、价格与交付影响因素：写明价格的主要影响因素（材质 / 数量 / 涂装 / 物流 / 加急 / 验收 / 检测），并给出一个区间（如 "DN50 球阀单价 80-260 元"）；写明交付周期常见区间（标准件 / 非标件 / 定制件分别多少天）。
   - 六、常见误区与验收建议：写出 5-7 个采购常见误区或被砸过坑的点，每条用一句话说清楚误区是什么 + 正确做法。
3. 每个章节必须自成段落，不要二级嵌套；每条要点尽量给可量化数字或对照档位，不要只写形容词。
4. 标题（title）控制 22-38 字，自然包含"{$keyword}"或同义实体；摘要（summary）控制 100-150 字，先点明问题再概括方案。
5. 给 5-8 个 tags（短词），覆盖：产品实体 / 采购意图 / 行业类目 / 标准号 / 地域产业带（华东/华南/华北/西南任选一）。

{$checklist}

返回 JSON：
{
  "title": "...",
  "summary": "...",
  "sections": ["第一章正文", "第二章正文", "第三章正文", "第四章正文", "第五章正文", "第六章正文"],
  "section_titles": ["一、用途与典型工况","二、规格与选型要点","三、询价单与采购清单","四、品牌厂家与产业带","五、价格与交付影响因素","六、常见误区与验收建议"],
  "specs_table": [
    {"name":"规格档位 1", "params":"具体参数串"},
    {"name":"规格档位 2", "params":"..."},
    {"name":"规格档位 3", "params":"..."},
    {"name":"规格档位 4", "params":"..."}
  ],
  "tags": ["...","..."]
}
只返回 JSON，不要任何额外说明。
PROMPT;
    }

    /**
     * @return array<string,array{err:string,body:string}>
     */
    private function callBatch(array $batch): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $slug => $meta) {
            $prompt = $this->buildPrompt($slug, $meta);
            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => LLMPromptKit::buildSystem('flagship_writer', ['anti_ai_slop', 'industrial_quantify', 'no_markdown', 'strict_json'])],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => LLMPromptKit::temperature('flagship'),
                'max_tokens' => 5500,
            ];
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 90,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$slug] = $ch;
        }
        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($mrc !== CURLM_OK) break;
            if ($running > 0) curl_multi_select($mh, 1.0);
        } while ($running > 0);

        $out = [];
        foreach ($handles as $slug => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $errMsg = '';
            if ($err) $errMsg = $err;
            elseif ($code !== 200) $errMsg = "http_$code";
            $out[$slug] = ['err' => $errMsg, 'body' => is_string($body) ? $body : ''];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function parseResponse(string $rawBody): ?array
    {
        $j = json_decode($rawBody, true);
        if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) return null;
        $content = (string)$j['choices'][0]['message']['content'];
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', trim($content));
        $first = strpos($content, '{');
        $last = strrpos($content, '}');
        if ($first === false || $last === false || $last <= $first) return null;
        $content = substr($content, $first, $last - $first + 1);
        $parsed = json_decode($content, true);
        if (!is_array($parsed) || empty($parsed['sections'])) return null;
        if (!is_array($parsed['sections'])) $parsed['sections'] = [(string)$parsed['sections']];
        $parsed['sections'] = array_values(array_filter(array_map(static function ($s): string {
            if (!is_scalar($s)) return '';
            return trim(strip_tags((string)$s));
        }, $parsed['sections']), static fn(string $s): bool => $s !== ''));
        if (count($parsed['sections']) < 5) return null;
        $parsed['title'] = trim((string)($parsed['title'] ?? ''));
        $parsed['summary'] = trim((string)($parsed['summary'] ?? ''));
        $parsed['section_titles'] = array_values(array_filter(array_map(static function ($t): string {
            return trim((string)$t);
        }, (array)($parsed['section_titles'] ?? [])), static fn(string $s): bool => $s !== ''));
        $parsed['specs_table'] = is_array($parsed['specs_table'] ?? null) ? $parsed['specs_table'] : [];
        $parsed['tags'] = array_values(array_filter(array_map(static function ($t): string {
            return trim((string)$t);
        }, (array)($parsed['tags'] ?? [])), static fn(string $s): bool => $s !== ''));
        if (!$parsed['tags']) $parsed['tags'] = ['工业', '采购', '供应链', '制造'];
        $parsed['tags'] = array_slice($parsed['tags'], 0, 8);
        return $parsed;
    }

    private function renderHtml(string $slug, array $meta, array $parsed): string
    {
        $title = htmlspecialchars($parsed['title'] ?: $meta['title'], ENT_QUOTES, 'UTF-8');
        $summary = htmlspecialchars($parsed['summary'], ENT_QUOTES, 'UTF-8');
        $keyword = htmlspecialchars($meta['keyword'], ENT_QUOTES, 'UTF-8');
        $industry = htmlspecialchars($meta['industry'], ENT_QUOTES, 'UTF-8');
        $category = $meta['category'];
        $tags = (array)$parsed['tags'];
        $sections = (array)$parsed['sections'];
        $sectionTitles = (array)$parsed['section_titles'];
        $defaultTitles = ['一、用途与典型工况', '二、规格与选型要点', '三、询价单与采购清单', '四、品牌厂家与产业带', '五、价格与交付影响因素', '六、常见误区与验收建议'];
        for ($i = 0; $i < count($sections); $i++) {
            if (!isset($sectionTitles[$i]) || $sectionTitles[$i] === '') {
                $sectionTitles[$i] = $defaultTitles[$i] ?? "第" . ($i + 1) . "节";
            }
        }
        $url = "https://guonika.com/topics/flagship/$slug.html";
        $datePublished = date('c');

        $sectionsHtml = '';
        foreach ($sections as $i => $body) {
            $sectionsHtml .= '<section class="flagship-section">';
            $sectionsHtml .= '<h2>' . htmlspecialchars($sectionTitles[$i] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>';
            $paragraphs = preg_split('/\n+/u', trim($body));
            foreach ($paragraphs as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $sectionsHtml .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $sectionsHtml .= '</section>';
        }

        // 规格表（如有）
        $specsHtml = '';
        if (!empty($parsed['specs_table'])) {
            $specsHtml .= '<section class="flagship-specs"><h2>典型规格档位对照</h2><table class="flagship-specs-table"><thead><tr><th>规格档位</th><th>关键参数</th></tr></thead><tbody>';
            foreach ($parsed['specs_table'] as $row) {
                if (!is_array($row)) continue;
                $name = htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $params = htmlspecialchars((string)($row['params'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($name === '' && $params === '') continue;
                $specsHtml .= "<tr><td>{$name}</td><td>{$params}</td></tr>";
            }
            $specsHtml .= '</tbody></table></section>';
        }

        $tagsHtml = '';
        foreach ($tags as $t) {
            $te = htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
            $tagsHtml .= '<a class="flagship-tag" href="/products?q=' . urlencode((string)$t) . '" rel="nofollow">' . $te . '</a>';
        }

        // Schema：Article + FAQPage（询价清单作为 FAQ 友好）
        $articleSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $parsed['title'] ?: $meta['title'],
            'description' => $parsed['summary'],
            'image' => 'https://guonika.com/assets/img/cover/industrial.jpg',
            'datePublished' => $datePublished,
            'dateModified' => $datePublished,
            'author' => ['@type' => 'Organization', 'name' => '全球工业产业链'],
            'publisher' => ['@type' => 'Organization', 'name' => '全球工业产业链', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://guonika.com/assets/img/site-logo-horizontal.svg']],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'about' => $meta['keyword'],
            'articleSection' => $industry,
            'keywords' => implode(',', array_slice($tags, 0, 6)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $breadcrumbSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '行业旗舰指南', 'item' => 'https://guonika.com/topics/flagship/index.html'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $meta['keyword'], 'item' => $url],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 内链：产品 / 公司 / 行情聚合页
        $relatedHtml = '<aside class="flagship-aside"><div class="flagship-aside-card"><h3>继续浏览</h3><ul>';
        $relatedHtml .= '<li><a href="/products?q=' . urlencode($meta['keyword']) . '">' . $keyword . ' 全部产品</a></li>';
        $relatedHtml .= '<li><a href="/companies?q=' . urlencode($meta['keyword']) . '">' . $keyword . ' 厂家供应商</a></li>';
        $relatedHtml .= '<li><a href="/topics/quotes/index.html">价格行情中心</a></li>';
        $relatedHtml .= '<li><a href="/topics/industrial-procurement-center.html">工业采购专题</a></li>';
        $relatedHtml .= '<li><a href="/news?q=' . urlencode($meta['keyword']) . '">' . $keyword . ' 行业资讯</a></li>';
        $relatedHtml .= '</ul></div></aside>';

        $disclaimer = '<div class="flagship-ai-disclaimer" role="note">本页内容由 AI 协助整理，参数与价格区间仅供采购方初步选型参考；实际成交以厂家最新报价与合同为准。</div>';

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{$title} - 全球工业产业链</title>
<meta name="description" content="{$summary}">
<meta name="keywords" content="{$keyword},{$industry},采购,选型,询价,厂家">
<link rel="canonical" href="{$url}">
<link rel="alternate" hreflang="zh-CN" href="{$url}">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.flagship-shell{max-width:1180px;margin:0 auto;padding:18px 14px 40px;display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:16px}
.flagship-hero{grid-column:1/-1;background:#fff8f0;border:1px solid #f4d97f;padding:20px 22px;margin-bottom:8px}
.flagship-hero .kicker{display:inline-block;background:#fff3df;border:1px solid #f4ba3a;color:#a96a07;font-size:12px;padding:2px 8px;margin-bottom:6px}
.flagship-hero h1{margin:0 0 10px;font-size:24px;color:#222;line-height:1.4}
.flagship-hero p{margin:0;color:#444;line-height:1.85}
.flagship-meta{margin-top:10px;font-size:12px;color:#7a7a7a}
.flagship-meta a{color:#0066cc;margin:0 6px}
.flagship-ai-disclaimer{background:#fffbe6;border:1px solid #f4d97f;color:#7a5b00;padding:8px 12px;font-size:12px;margin:0 0 14px;line-height:1.7}
.flagship-section{background:#fff;border:1px solid #e5e7eb;padding:18px 20px;margin-bottom:12px}
.flagship-section h2{margin:0 0 10px;font-size:18px;color:#1f3a63;border-bottom:2px solid #f4d97f;padding-bottom:6px}
.flagship-section p{margin:0 0 10px;line-height:1.95;color:#222;font-size:14px}
.flagship-specs{background:#fff;border:1px solid #e5e7eb;padding:18px 20px;margin-bottom:12px}
.flagship-specs h2{margin:0 0 10px;font-size:18px;color:#1f3a63;border-bottom:2px solid #f4d97f;padding-bottom:6px}
.flagship-specs-table{width:100%;border-collapse:collapse;font-size:13px}
.flagship-specs-table th,.flagship-specs-table td{border:1px solid #e0e4ea;padding:8px 10px;text-align:left;vertical-align:top}
.flagship-specs-table th{background:#fafbfc;color:#444;font-weight:600;width:140px}
.flagship-tags{margin-top:14px}
.flagship-tag{display:inline-block;padding:3px 10px;background:#fff3df;border:1px solid #f4d97f;color:#a06a07;font-size:12px;margin:0 4px 6px 0;text-decoration:none;border-radius:0}
.flagship-tag:hover{background:#f4d97f;color:#5a3500}
.flagship-cta-bar{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.flagship-cta-bar a{display:inline-block;padding:8px 14px;border:1px solid #c9a45c;background:#fff;color:#5a3500;font-size:13px;text-decoration:none;border-radius:0}
.flagship-cta-bar a.primary{background:#c9a45c;color:#fff}
.flagship-cta-bar a:hover{background:#a8852f;color:#fff;border-color:#a8852f}
.flagship-aside .flagship-aside-card{background:#fff;border:1px solid #e5e7eb;padding:14px 16px;position:sticky;top:14px}
.flagship-aside h3{margin:0 0 10px;font-size:15px;color:#1f3a63;border-bottom:1px solid #f4d97f;padding-bottom:5px}
.flagship-aside ul{list-style:none;margin:0;padding:0}
.flagship-aside li{margin-bottom:6px;line-height:1.7}
.flagship-aside a{color:#0066cc;text-decoration:none;font-size:13px}
.flagship-aside a:hover{color:#c9302c;text-decoration:underline}
@media (max-width:991px){.flagship-shell{grid-template-columns:1fr}}
</style>
<script type="application/ld+json">{$articleSchema}</script>
<script type="application/ld+json">{$breadcrumbSchema}</script>
</head>
<body class="topic-static-page topic-static-page-flagship">
<header class="top-bar bg-primary text-white py-2">
<div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div>
</header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0">
<div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/flagship/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">旗舰指南</a></div>
</nav>
<main class="flagship-shell">
<div class="flagship-hero">
<span class="kicker">行业旗舰采购指南</span>
<h1>{$title}</h1>
<p>{$summary}</p>
<div class="flagship-meta">
<i class="bi bi-tag"></i> 关键词：<a href="/products?q={$keyword}">{$keyword}</a>
&nbsp;|&nbsp; <i class="bi bi-grid"></i> 行业：<a href="/products?q={$industry}">{$industry}</a>
&nbsp;|&nbsp; <i class="bi bi-calendar"></i> 更新：{$datePublished}
</div>
<div class="flagship-cta-bar">
<a class="primary" href="/products?q={$keyword}"><i class="bi bi-box-seam"></i> 查 {$keyword} 实物产品</a>
<a href="/companies?q={$keyword}"><i class="bi bi-buildings"></i> 找 {$keyword} 厂家</a>
<a href="/news?q={$keyword}"><i class="bi bi-newspaper"></i> 行业资讯</a>
<a href="tel:400-880-6688"><i class="bi bi-telephone"></i> 400-880-6688</a>
</div>
</div>
<div style="grid-column:1/2">
{$disclaimer}
{$sectionsHtml}
{$specsHtml}
<div class="flagship-tags"><strong style="font-size:13px;color:#444;margin-right:6px">相关词：</strong>{$tagsHtml}</div>
</div>
{$relatedHtml}
</main>
<footer style="background:#0b1623;color:#aab2bd;padding:18px 14px;text-align:center;font-size:12px"><div>&copy; 全球工业产业链 · <a href="/" style="color:#aab2bd;text-decoration:none">首页</a> · <a href="/topics/flagship/index.html" style="color:#aab2bd;text-decoration:none">旗舰指南</a> · 豫ICP备2023034280号-2</div></footer>
</body>
</html>
HTML;
    }

    private function writeIndex(): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        $items = [];
        foreach (self::TOPICS as $slug => $meta) {
            $path = $outDir . '/' . $slug . '.html';
            if (!is_file($path)) continue;
            $items[] = $slug;
        }
        if (!$items) return;

        $listSchema = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => '行业旗舰采购指南', 'itemListElement' => []];
        $cardsHtml = '';
        $pos = 1;
        foreach ($items as $slug) {
            $meta = self::TOPICS[$slug];
            $url = "https://guonika.com/topics/flagship/$slug.html";
            $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
            $kw = htmlspecialchars($meta['keyword'], ENT_QUOTES, 'UTF-8');
            $ind = htmlspecialchars($meta['industry'], ENT_QUOTES, 'UTF-8');
            $listSchema['itemListElement'][] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $meta['title'], 'url' => $url];
            $cardsHtml .= "<a class=\"flagship-card\" href=\"/topics/flagship/{$slug}.html\"><span class=\"kicker\">{$ind}</span><h3>{$title}</h3><p>关键词：{$kw}</p><span class=\"meta\">查看完整指南 →</span></a>";
        }
        $listSchemaJson = json_encode($listSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $count = count($items);
        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>行业旗舰采购指南 · {$count} 篇真实选型与询价手册 - 全球工业产业链</title>
<meta name="description" content="覆盖工业泵、阀门、电机、轴承、不锈钢、机床、机器人等 {$count} 大行业的旗舰采购指南，每篇含规格档位、询价清单、厂家产业带与价格区间。">
<link rel="canonical" href="https://guonika.com/topics/flagship/index.html">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.flagship-index-shell{max-width:1180px;margin:0 auto;padding:18px 14px 40px}
.flagship-index-hero{background:linear-gradient(125deg,#173451 0%,#245e92 100%);color:#fff;padding:30px 26px;margin-bottom:16px;border-radius:0}
.flagship-index-hero h1{margin:0 0 10px;font-size:28px}
.flagship-index-hero p{margin:0;line-height:1.8;color:rgba(255,255,255,.92)}
.flagship-index-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
.flagship-card{display:block;background:#fff;border:1px solid #e0e4ea;padding:14px 16px;text-decoration:none;color:inherit;transition:border-color .15s}
.flagship-card:hover{border-color:#c9a45c}
.flagship-card .kicker{display:inline-block;background:#fff3df;border:1px solid #f4ba3a;color:#a06a07;font-size:11px;padding:2px 7px;margin-bottom:6px}
.flagship-card h3{margin:6px 0;font-size:15px;color:#1f3a63;line-height:1.5}
.flagship-card p{margin:0 0 8px;color:#5d6f84;font-size:13px}
.flagship-card .meta{font-size:12px;color:#0066cc}
</style>
<script type="application/ld+json">{$listSchemaJson}</script>
</head>
<body>
<header class="top-bar bg-primary text-white py-2"><div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div></header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0"><div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/flagship/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">旗舰指南</a></div></nav>
<main class="flagship-index-shell">
<div class="flagship-index-hero">
<h1>行业旗舰采购指南 · {$count} 篇</h1>
<p>覆盖工业泵阀、电机减速、空压、轴承紧固、不锈钢钢板、电缆机床、机器人、传感器、化工材料、暖通水处理、电池照明等核心行业的旗舰采购指南。每篇内容由 AI 协助整理，包含规格档位对照、询价清单、产业带分布、价格与交付影响因素以及常见误区。</p>
</div>
<div class="flagship-index-grid">{$cardsHtml}</div>
</main>
<footer style="background:#0b1623;color:#aab2bd;padding:18px 14px;text-align:center;font-size:12px"><div>&copy; 全球工业产业链 · 豫ICP备2023034280号-2</div></footer>
</body>
</html>
HTML;
        file_put_contents($outDir . '/index.html', $html);
        $this->log("INDEX written items=$count");
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

(new FlagshipPagesBuilder($opts))->run();
