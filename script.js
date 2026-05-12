/**
 * PHO KPI Dashboard — script.js
 * Chart helper functions for Chart.js
 */

/**
 * สร้าง Bar Chart แนวตั้งพร้อมสีแยกตามเงื่อนไข Pass/Fail
 * @param {string} canvasId
 * @param {string[]} labels
 * @param {number[]} dataValues - ผลงานจริง
 * @param {number[]} targets    - เป้าหมาย
 * @param {string[]} operators  - เงื่อนไข (>=, <=, >, <, =)
 */
function createStatusKpiChart(canvasId, labels, dataValues, targets, operators) {
    const ctx = document.getElementById(canvasId)?.getContext('2d');
    if (!ctx) return;

    const passColor = '#16a34a';
    const failColor = '#dc2626';
    const passBg    = 'rgba(22,163,74,0.15)';
    const failBg    = 'rgba(220,38,38,0.15)';

    const isPassArr = dataValues.map((val, i) => evaluateKpi(val, targets[i], operators[i]));
    const barColors = isPassArr.map(p => p ? passColor : failColor);
    const bgColors  = isPassArr.map(p => p ? passBg    : failBg);

    // Target line dataset
    const targetDataset = {
        type: 'line',
        label: 'เป้าหมาย',
        data: targets,
        borderColor: 'rgba(100,116,139,0.6)',
        borderWidth: 1.5,
        borderDash: [5, 4],
        pointRadius: 3,
        pointBackgroundColor: 'rgba(100,116,139,0.7)',
        fill: false,
        tension: 0,
        order: 0
    };

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'ผลงานจริง',
                    data: dataValues,
                    backgroundColor: barColors,
                    borderColor: barColors,
                    borderRadius: 6,
                    borderWidth: 0,
                    barThickness: 32,
                    order: 1
                },
                targetDataset
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        boxWidth: 12,
                        font: { family: 'Sarabun', size: 12 }
                    }
                },
                tooltip: {
                    titleFont: { family: 'Sarabun', size: 13 },
                    bodyFont:  { family: 'Sarabun', size: 12 },
                    callbacks: {
                        title: ctx => {
                            const label = ctx[0].label;
                            return label.length > 40 ? label.substring(0, 40) + '…' : label;
                        },
                        label: ctx => {
                            if (ctx.datasetIndex === 0) {
                                const i = ctx.dataIndex;
                                const pass = isPassArr[i];
                                return ` ผลงาน: ${ctx.parsed.y.toFixed(2)}% ${pass ? '✓ บรรลุ' : '✗ ต่ำกว่าเป้า'}`;
                            }
                            return ` เป้าหมาย: ${ctx.parsed.y}%`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        font: { family: 'Sarabun', size: 11 },
                        callback: v => v + '%'
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        font: { family: 'Sarabun', size: 10 },
                        callback: function (value) {
                            const label = this.getLabelForValue(value);
                            return label.length > 14 ? label.substr(0, 14) + '…' : label;
                        },
                        maxRotation: 45,
                        minRotation: 30
                    }
                }
            }
        }
    });
}

/**
 * สร้าง Doughnut Chart สัดส่วน Pass/Fail
 * @param {string}   canvasId
 * @param {string[]} labels
 * @param {number[]} dataPoints
 */
function createDoughnutChart(canvasId, labels, dataPoints) {
    const ctx = document.getElementById(canvasId)?.getContext('2d');
    if (!ctx) return;

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: dataPoints,
                backgroundColor: ['#16a34a', '#dc2626'],
                hoverBackgroundColor: ['#15803d', '#b91c1c'],
                borderWidth: 3,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: 'Sarabun', size: 12 },
                        padding: 16,
                        usePointStyle: true,
                        pointStyleWidth: 10
                    }
                },
                tooltip: {
                    titleFont: { family: 'Sarabun' },
                    bodyFont:  { family: 'Sarabun' },
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed} ตัวชี้วัด`
                    }
                }
            }
        }
    });
}

/**
 * ประเมินผล KPI ตาม operator
 * @param {number} result
 * @param {number} target
 * @param {string} operator
 * @returns {boolean}
 */
function evaluateKpi(result, target, operator) {
    switch (operator) {
        case '>=': return result >= target;
        case '<=': return result <= target;
        case '>':  return result > target;
        case '<':  return result < target;
        case '=':  return result === target;
        default:   return result >= target;
    }
}