/**
 * NexoPC - Motor de Precios Calibrados para el Mercado Peruano (Soles PEN con IGV)
 * Basado en niveles de tiendas locales de referencia:
 * Memory Kings, Impacto, Sercoplus, Compu & Visión, Deltron e Infotec.
 */

function cleanStr(val) {
  return String(val || '').trim();
}

function calculateRAMPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const mfr = cleanStr(item.metadata?.manufacturer).toUpperCase();
  const ramType = cleanStr(item.ram_type).toUpperCase();
  const capacity = Number(item.capacity) || 32;
  const speed = Number(item.speed) || 5200;
  const isRGB = item.rgb || name.includes('RGB');
  const isDominatorOrTrident = name.includes('DOMINATOR') || name.includes('TRIDENT Z5') || name.includes('TITANIUM');

  if (ramType.includes('DDR5')) {
    if (capacity >= 64) {
      if (isDominatorOrTrident || speed >= 6400) return 2190;
      if (isRGB || speed >= 6000) return 1990;
      return 1850;
    }
    if (capacity >= 32) {
      // 32GB DDR5: Mercado peruano real ~S/ 1,180 a S/ 1,450
      if (isDominatorOrTrident || speed >= 6400) return 1390;
      if (speed >= 6000) return isRGB ? 1290 : 1240;
      if (speed >= 5600) return 1190; // Ej: Corsair Vengeance 5600 CL36
      return 1120; // 4800 - 5200
    }
    // 16GB DDR5
    if (speed >= 6000 || isRGB) return 620;
    return 540;
  }

  // DDR4
  if (capacity >= 64) {
    return isRGB ? 890 : 790;
  }
  if (capacity >= 32) {
    if (isRGB || speed >= 3600) return 490;
    return 430;
  }
  // 16GB DDR4
  if (isRGB || speed >= 3600) return 270;
  return 230;
}

function calculateGPUPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const chipset = cleanStr(item.chipset).toUpperCase();
  const mfr = cleanStr(item.metadata?.manufacturer).toUpperCase();
  const isPremiumTier = name.includes('STRIX') || name.includes('SUPRIM') || name.includes('AORUS MASTER');

  // RTX 4000 Series
  if (chipset.includes('4090') || name.includes('4090')) return isPremiumTier ? 9450 : 8890;
  if (chipset.includes('4080 SUPER') || name.includes('4080 SUPER')) return isPremiumTier ? 5590 : 5290;
  if (chipset.includes('4080') || name.includes('4080')) return isPremiumTier ? 5390 : 5090;
  if (chipset.includes('4070 TI SUPER') || name.includes('4070 TI SUPER')) return isPremiumTier ? 4190 : 3890;
  if (chipset.includes('4070 TI') || name.includes('4070 TI')) return isPremiumTier ? 3790 : 3550;
  if (chipset.includes('4070 SUPER') || name.includes('4070 SUPER')) return isPremiumTier ? 3190 : 2950;
  if (chipset.includes('4070') || name.includes('4070')) return isPremiumTier ? 2890 : 2690;
  if (chipset.includes('4060 TI') || name.includes('4060 TI')) {
    const is16GB = (item.memory?.size_gb === 16) || name.includes('16G');
    return is16GB ? 2190 : 1890;
  }
  if (chipset.includes('4060') || name.includes('4060')) return 1450;

  // RTX 3000 Series
  if (chipset.includes('3090 TI') || name.includes('3090 TI')) return 4490;
  if (chipset.includes('3090') || name.includes('3090')) return 3890;
  if (chipset.includes('3080 TI') || name.includes('3080 TI')) return 3090;
  if (chipset.includes('3080') || name.includes('3080')) return 2690;
  if (chipset.includes('3070 TI') || name.includes('3070 TI')) return 2090;
  if (chipset.includes('3070') || name.includes('3070')) return 1850;
  if (chipset.includes('3060 TI') || name.includes('3060 TI')) return 1490;
  if (chipset.includes('3060') || name.includes('3060')) {
    const is8GB = (item.memory?.size_gb === 8) || name.includes('8G');
    return is8GB ? 1180 : 1280;
  }
  if (chipset.includes('3050') || name.includes('3050')) return 950;

  // GTX Series
  if (chipset.includes('1660 SUPER') || name.includes('1660 SUPER')) return 880;
  if (chipset.includes('1660 TI') || name.includes('1660 TI')) return 890;
  if (chipset.includes('1660') || name.includes('1660')) return 820;
  if (chipset.includes('1650') || name.includes('1650')) return 720;

  // AMD Radeon 7000 Series
  if (chipset.includes('7900 XTX') || name.includes('7900 XTX')) return 4790;
  if (chipset.includes('7900 XT') || name.includes('7900 XT')) return 3890;
  if (chipset.includes('7900 GRE') || name.includes('7900 GRE')) return 2890;
  if (chipset.includes('7800 XT') || name.includes('7800 XT')) return 2590;
  if (chipset.includes('7700 XT') || name.includes('7700 XT')) return 2150;
  if (chipset.includes('7600 XT') || name.includes('7600 XT')) return 1590;
  if (chipset.includes('7600') || name.includes('7600')) return 1250;

  // AMD Radeon 6000 Series
  if (chipset.includes('6950 XT') || name.includes('6950 XT')) return 2990;
  if (chipset.includes('6900 XT') || name.includes('6900 XT')) return 2790;
  if (chipset.includes('6800 XT') || name.includes('6800 XT')) return 2390;
  if (chipset.includes('6800') || name.includes('6800')) return 2090;
  if (chipset.includes('6750 XT') || name.includes('6750 XT')) return 1850;
  if (chipset.includes('6700 XT') || name.includes('6700 XT')) return 1690;
  if (chipset.includes('6650 XT') || name.includes('6650 XT')) return 1320;
  if (chipset.includes('6600 XT') || name.includes('6600 XT')) return 1220;
  if (chipset.includes('6600') || name.includes('6600')) return 980;

  // Intel Arc
  if (chipset.includes('A770') || name.includes('A770')) return 1390;
  if (chipset.includes('A750') || name.includes('A750')) return 1090;

  return 1750;
}

function calculateCPUPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();

  // AMD Ryzen 9000
  if (name.includes('9950X')) return 2990;
  if (name.includes('9900X')) return 2290;
  if (name.includes('9700X')) return 1690;
  if (name.includes('9600X')) return 1290;

  // AMD Ryzen 7000
  if (name.includes('7950X3D')) return 2890;
  if (name.includes('7950X')) return 2650;
  if (name.includes('7900X3D')) return 2250;
  if (name.includes('7900X') || name.includes('7900')) return 2050;
  if (name.includes('7800X3D')) return 1990;
  if (name.includes('7700X') || name.includes('7700')) return 1450;
  if (name.includes('7600X')) return 1090;
  if (name.includes('7600')) return 990;
  if (name.includes('7500F')) return 820;

  // AMD Ryzen 5000 / AM4
  if (name.includes('5800X3D') || name.includes('5700X3D')) return 1150;
  if (name.includes('5950X')) return 1890;
  if (name.includes('5900X')) return 1450;
  if (name.includes('5800X')) return 890;
  if (name.includes('5700X')) return 790;
  if (name.includes('5600X')) return 650;
  if (name.includes('5600T') || name.includes('5600')) return 580;
  if (name.includes('5600G') || name.includes('5500')) return 520;
  if (name.includes('3600') || name.includes('4500') || name.includes('3400G') || name.includes('1800X')) return 380;

  // Intel Core 14th / 13th Gen
  if (name.includes('14900KS')) return 3290;
  if (name.includes('14900K')) return 2850;
  if (name.includes('13900KS')) return 2950;
  if (name.includes('13900K') || name.includes('13900')) return 2690;
  if (name.includes('14700K')) return 1950;
  if (name.includes('14700')) return 1690;
  if (name.includes('13700K')) return 1790;
  if (name.includes('13700')) return 1590;
  if (name.includes('14600K')) return 1390;
  if (name.includes('13600K')) return 1290;
  if (name.includes('14500') || name.includes('14400')) return 950;
  if (name.includes('13500') || name.includes('13400')) return 880;
  if (name.includes('14100')) return 520;
  if (name.includes('13100')) return 480;

  // Intel Core 12th / Older
  if (name.includes('12900K')) return 1650;
  if (name.includes('12700K')) return 1250;
  if (name.includes('12600K')) return 890;
  if (name.includes('12400')) return 640;
  if (name.includes('12100')) return 420;
  if (name.includes('10850K') || name.includes('10900K')) return 1150;

  return 990;
}

function calculateMotherboardPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const chipset = cleanStr(item.chipset).toUpperCase();

  // Top Enthusiast Chipsets
  if (chipset.includes('X670E') || chipset.includes('Z790')) {
    if (name.includes('MAXIMUS') || name.includes('CROSSHAIR') || name.includes('GODLIKE') || name.includes('AORUS MASTER')) {
      return 2750;
    }
    if (name.includes('STRIX') || name.includes('AORUS PRO') || name.includes('CARBON') || name.includes('TAICHI')) {
      return 1890;
    }
    return 1390; // TUF, Tomahawk, Prime Z790, AORUS Elite
  }

  if (chipset.includes('X670') || chipset.includes('B650E') || chipset.includes('Z690')) {
    if (name.includes('STRIX') || name.includes('AORUS')) return 1450;
    return 1150;
  }

  // Mid Gaming Chipsets
  if (chipset.includes('B650') || chipset.includes('B760')) {
    if (name.includes('WIFI') || name.includes('TUF') || name.includes('MORTAR') || name.includes('AORUS') || name.includes('STRIX')) {
      return 890;
    }
    return 690;
  }

  // AM4 / Entry
  if (chipset.includes('B550')) {
    if (name.includes('WIFI') || name.includes('ROG') || name.includes('TUF') || name.includes('AORUS')) return 580;
    return 460;
  }
  if (chipset.includes('A620')) return 480;
  if (chipset.includes('H610') || chipset.includes('A520') || chipset.includes('B450')) return 350;

  return 650;
}

function calculateStoragePrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const cap = Number(item.capacity) || 1000;
  const isHighTier = name.includes('990 PRO') || name.includes('980 PRO') || name.includes('SN850X') || name.includes('KC3000') || name.includes('T500') || name.includes('FIRECUDA');
  const isHDD = item.storage_type?.toLowerCase().includes('hdd') || name.includes('BARRACUDA') || name.includes('IRONWOLF') || name.includes('PURPLE') || name.includes('WD BLUE 3.5');

  if (isHDD) {
    if (cap >= 4000) return 380;
    if (cap >= 2000) return 250;
    return 190;
  }

  // NVMe SSD
  if (cap >= 4000) {
    return isHighTier ? 1650 : 1290;
  }
  if (cap >= 2000) {
    return isHighTier ? 790 : 580;
  }
  if (cap >= 1000) {
    return isHighTier ? 460 : 320;
  }
  // 500GB / SATA
  return 220;
}

function calculatePSUPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const watts = Number(item.wattage) || 650;
  const eff = cleanStr(item.efficiency_rating).toUpperCase();

  if (watts >= 1200) return 1290;
  if (watts >= 1000) return eff.includes('PLATINUM') || eff.includes('TITANIUM') ? 1150 : 890;
  if (watts >= 850) return eff.includes('GOLD') || eff.includes('PLATINUM') ? 640 : 520;
  if (watts >= 750) return eff.includes('GOLD') ? 490 : 390;
  if (watts >= 650) return eff.includes('GOLD') ? 390 : 290;
  if (watts >= 550) return 240;
  return 210;
}

function calculateCasePrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const mfr = cleanStr(item.metadata?.manufacturer).toUpperCase();

  if (name.includes('O11') || name.includes('VISION') || name.includes('H9') || name.includes('5000D') || name.includes('6500X') || name.includes('HYTE Y70') || name.includes('HYTE Y60')) {
    return 790;
  }
  if (name.includes('H7') || name.includes('H5') || name.includes('4000D') || name.includes('KING 95') || name.includes('XT PRO') || name.includes('LANCOOL')) {
    return 480;
  }
  if (name.includes('AIR 903') || name.includes('AIR 100') || name.includes('CC560') || name.includes('MASTERBOX') || name.includes('TD500')) {
    return 320;
  }
  return 230;
}

function calculateCoolerPrice(item) {
  const name = cleanStr(item.metadata?.name).toUpperCase();
  const mfr = cleanStr(item.metadata?.manufacturer).toUpperCase();
  const isLiquid = item.water_cooled || name.includes('LIQUID') || name.includes('AIO') || name.includes('KRAKEN') || name.includes('ICUE') || name.includes('LT720') || name.includes('LS720') || name.includes('GALAHAD');
  const radSize = Number(item.radiator_size) || (name.includes('360') ? 360 : (name.includes('240') ? 240 : 0));

  if (isLiquid) {
    if (radSize >= 360 || name.includes('360')) {
      if (name.includes('KRAKEN ELITE') || name.includes('RYUJIN') || name.includes('ICUE LINK')) return 1250;
      if (name.includes('KRAKEN') || name.includes('GALAHAD')) return 890;
      return 580; // DeepCool LT720, Thermalright, etc.
    }
    if (radSize >= 240 || name.includes('240')) {
      if (name.includes('KRAKEN')) return 620;
      return 420;
    }
    return 380;
  }

  // Air Coolers
  if (name.includes('NH-D15') || name.includes('ASSASSIN IV') || name.includes('AK620') || name.includes('PEERLESS ASSASSIN') || name.includes('PHANTOM SPIRIT') || name.includes('DARK ROCK PRO')) {
    if (name.includes('NOCTUA')) return 480;
    return 290;
  }
  if (name.includes('AK400') || name.includes('HYPER 212') || name.includes('AG400') || name.includes('SE-224')) {
    return 150;
  }
  return 120;
}

/**
 * Función principal para obtener el precio de mercado peruano (PEN).
 */
function getPeruPrice(item, category) {
  let price = 0;
  switch (category) {
    case 'RAM':
    case 'Memoria RAM':
      price = calculateRAMPrice(item);
      break;
    case 'GPU':
    case 'Tarjetas Gráficas':
      price = calculateGPUPrice(item);
      break;
    case 'CPU':
    case 'Procesadores':
      price = calculateCPUPrice(item);
      break;
    case 'Motherboard':
    case 'Placas Madre':
      price = calculateMotherboardPrice(item);
      break;
    case 'Storage':
    case 'Almacenamiento':
      price = calculateStoragePrice(item);
      break;
    case 'PSU':
    case 'Fuentes de Poder':
      price = calculatePSUPrice(item);
      break;
    case 'Case':
    case 'PCCase':
    case 'Gabinetes':
      price = calculateCasePrice(item);
      break;
    case 'Cooler':
    case 'CPUCooler':
    case 'Refrigeración':
      price = calculateCoolerPrice(item);
      break;
    default:
      price = 250;
  }
  return Math.round(price);
}

module.exports = {
  getPeruPrice,
  calculateRAMPrice,
  calculateGPUPrice,
  calculateCPUPrice,
  calculateMotherboardPrice,
  calculateStoragePrice,
  calculatePSUPrice,
  calculateCasePrice,
  calculateCoolerPrice
};
