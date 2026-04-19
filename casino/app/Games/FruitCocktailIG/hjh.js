const { reelsStrip } = require('./reelsStrip')
const { payTable } = require('../igroSoft/payTable')
const { linesId } = require('../igroSoft/linesId')
const { shuffleArray } = require('../igroSoft/shuffleArray')
const { getTime } = require('../igroSoft/getTime')
const { bonusSym } = require('../igroSoft/bonusSym')
const { bonusWins } = require('../igroSoft/bonusWins')
const { newRiskCard } = require('../igroSoft/newRiskCard')

// ============ НАСТРОЙКИ RTP ИЗ ФАЙЛА ============
const REEL_STRIP_WEIGHTS = {
    1: 10, 2: 10, 3: 10, 4: 10, 5: 10, 6: 10,
    7: 10, 8: 10, 1: 10, 2: 10, 3: 10, 4: 10,
    0: 5
}

const WIN_BONUS_WEIGHTS = {
    10: 5, 50: 5, 100: 5, 200: 5, 300: 5, 400: 5, 500: 5
}

// Глобальный банк игры (хранится в памяти или файле)
let gameBank = 1000000  // Начальный банк казино
let gameStatIn = 0
let gameStatOut = 0

function getRandomFromWeights(weights) {
    const totalWeight = Object.values(weights).reduce((a, b) => a + b, 0)
    let random = Math.random() * totalWeight
    for (const [key, weight] of Object.entries(weights)) {
        if (random < weight) {
            return typeof key === 'string' ? parseInt(key) : key
        }
        random -= weight
    }
    return Object.keys(weights)[0]
}

async function handleGame(config) {
    try {
        const { Lines, Bet, Denom } = config
        const reelStrip = reelsStrip()[0]
        const totalBet = Bet * Lines * (Denom / 100)
        const pay = payTable()
        const linesConfig = linesId()
        const WILD = 2
        let totalWin = 0
        const SCATTER = 0
        const winningLines = []
        const positions = []

        // ============ 1. ПРОВЕРКА БАНКА ============
        // Если в банке меньше 100 ставок - не даем большие выигрыши
        const minBankForBonus = totalBet * 100
        const minBankForBigWin = totalBet * 50

        // ============ 2. ОПРЕДЕЛЯЕМ ШАНСЫ В ЗАВИСИМОСТИ ОТ БАНКА ============
        let bonusChance = 50      // 1 из 50 (2%)
        let winChance = 30        // 1 из 30 (3.3%)

        // Если банк маленький - уменьшаем шансы на выигрыш
        if (gameBank < minBankForBonus) {
            bonusChance = 200     // 1 из 200 (0.5%) - бонус почти не выпадает
            winChance = 100       // 1 из 100 (1%) - выигрыши реже
        }

        // Если банк большой - увеличиваем шансы
        if (gameBank > minBankForBonus * 10) {
            bonusChance = 25      // 1 из 25 (4%) - бонус чаще
            winChance = 15        // 1 из 15 (6.6%) - выигрыши чаще
        }

        // ============ 3. РЕШАЕМ: БУДЕТ ЛИ БОНУС (ЗАВИСИТ ОТ БАНКА) ============
        const willBonus = (Math.floor(Math.random() * bonusChance) === 0) && (gameBank >= minBankForBonus)

        // ============ 4. РЕШАЕМ: БУДЕТ ЛИ ВЫИГРЫШ (ЗАВИСИТ ОТ БАНКА) ============
        const willWin = Math.floor(Math.random() * winChance) === 0

        // ============ 5. ВЫБИРАЕМ ПОЗИЦИИ ============
        for (let reel = 0; reel < 5; reel++) {
            const maxPosition = reelStrip.length - 3
            positions.push(Math.floor(Math.random() * (maxPosition + 1)))
        }

        const reels = [[], [], []]
        for (let reel = 0; reel < 5; reel++) {
            const pos = positions[reel]
            reels[0][reel] = reelStrip[pos]
            reels[1][reel] = reelStrip[pos + 1]
            reels[2][reel] = reelStrip[pos + 2]
        }

        // ============ 6. РАСЧЕТ ВЫИГРЫША ПО ЛИНИЯМ ============
        for (let lineNum = 1; lineNum <= Lines; lineNum++) {
            const pattern = linesConfig[lineNum]
            const symbols = []
            for (let reel = 0; reel < 5; reel++) {
                const rowIndex = pattern[reel] - 1
                symbols.push(reels[rowIndex][reel])
            }

            const firstSymbol = symbols[0]
            let matchCount = 1
            let matchedPositions = [0]

            if (firstSymbol === 1) {
                for (let i = 1; i < 5; i++) {
                    if (symbols[i] === 1) {
                        matchCount++
                        matchedPositions.push(i)
                    } else {
                        break
                    }
                }
            } else {
                for (let i = 1; i < 5; i++) {
                    if (symbols[i] === firstSymbol || symbols[i] === WILD) {
                        matchCount++
                        matchedPositions.push(i)
                    } else {
                        break
                    }
                }
            }

            const posArray = [-1, -1, -1, -1, -1]
            for (let i = 0; i < matchedPositions.length; i++) {
                const reelIdx = matchedPositions[i]
                posArray[reelIdx] = pattern[reelIdx] - 1
            }

            if (matchCount >= 3) {
                let winAmount = 0
                if (matchCount === 3) winAmount = pay[firstSymbol][3] * Bet
                if (matchCount === 4) winAmount = pay[firstSymbol][4] * Bet
                if (matchCount === 5) winAmount = pay[firstSymbol][5] * Bet

                // ============ 7. ПРОВЕРКА БАНКА ПЕРЕД ВЫПЛАТОЙ ============
                if (winAmount > 0 && gameBank >= winAmount * (Denom / 100)) {
                    totalWin += winAmount
                    winningLines.push({
                        Pos: posArray,
                        Element: firstSymbol,
                        Count: matchCount,
                        Line: lineNum,
                        Coef: winAmount / Bet,
                        Win: winAmount,
                    })
                }
            }
        }

        // ============ 8. ПРОВЕРКА СКАТТЕРОВ ============
        let scatterCount = 0
        for (let row = 0; row < 3; row++) {
            for (let reel = 0; reel < 5; reel++) {
                if (reels[row][reel] === SCATTER) scatterCount++
            }
        }

        // ============ 9. БОНУСНАЯ ИГРА (ЗАВИСИТ ОТ БАНКА) ============
        let bonusWin = 0
        let bonusGames = []
        let isBonus = false

        // Бонус только если: 1) сервер решил 2) есть скаттеры 3) есть деньги в банке
        if (willBonus && scatterCount >= 3 && gameBank >= minBankForBonus) {
            isBonus = true

            const bonusMultiplier = getRandomFromWeights(WIN_BONUS_WEIGHTS)
            const bonusPrizes = bonusWins()
            const shuffled = shuffleArray([...bonusPrizes])
            const bonusSymbols = bonusSym()
            let attempts = 3

            for (let i = 0; i < shuffled.length && attempts > 0; i++) {
                const multiplier = Math.floor(Math.random() * 3) + 1
                let win = shuffled[i] * Bet * multiplier

                if (i === 0 && bonusMultiplier > 10) {
                    win = bonusMultiplier * Bet * multiplier
                }

                if (gameBank >= win * (Denom / 100)) {
                    bonusWin += win
                    bonusGames.push({
                        Elem: bonusSymbols[shuffled[i]],
                        Count: multiplier,
                        Coef: shuffled[i],
                        Win: win,
                    })
                } else {
                    bonusGames.push(null)
                }

                if (win > 0) {
                    attempts--
                }
            }
        }
        if (willWin && totalWin === 0 && !isBonus && gameBank >= Bet * 5 * (Denom / 100)) {
            totalWin = Bet * 5
            winningLines.push({
                Pos: [1, 1, 1, -1, -1],
                Element: 5,
                Count: 3,
                Line: 1,
                Coef: 5,
                Win: totalWin,
            })
        }

        const finalWin = totalWin + bonusWin

        gameBank += totalBet
        // Вычитаем выигрыш из банка
        gameBank -= finalWin * (Denom / 100)
        // Обновляем статистику
        gameStatIn += totalBet
        gameStatOut += finalWin * (Denom / 100)

        // ============ 12. КАРТА ДЛЯ РИСК-ИГРЫ ============
        const riskCardId = newRiskCard()

        // ============ 13. ВОЗВРАЩАЕМ РЕЗУЛЬТАТ ============
        return {
            isBonus: isBonus,
            BonusWins: bonusWin,
            BonusGames: bonusGames,
            ScatterCount: scatterCount,
            gameBank: gameBank,  // Для отладки - текущий банк
            currentRTP: (gameStatOut / gameStatIn * 100).toFixed(2),
            data: {
                Amount: 500000,
                Credit: 500000,
                Denomination: Denom.toFixed(2),
                LastBet: totalBet,
                Bet: Bet,
                LastLines: Lines,
                LineWins: winningLines,
                NetPosition: (finalWin * (Denom / 100) - totalBet).toFixed(2),
                RawCredit: 500000,
                Reels: reels,
                RiskCard: riskCardId,
                TotalBet: totalBet,
                Win: finalWin,
                args: { Bet, Denom: Denom * 100, Lines },
                cmd: 'start',
                round: config.session?.data?.round + 1 || 1,
                time: getTime(),
            },
        }
    } catch (e) {
        console.log(e)
    }
}

module.exports = { handleGame }
