// Teaching calculations only. Percentages and rates are user-selected scenarios.
const LearningMath = Object.freeze({
  trade({quantity,buyPrice,sellPrice,buyFee,sellFee}){
    if(!Number.isSafeInteger(quantity)||quantity<1||quantity>1000000||![buyPrice,sellPrice,buyFee,sellFee].every(Number.isFinite)||buyPrice<=0||buyPrice>1e8||sellPrice<0||sellPrice>1e8||buyFee<0||buyFee>=100||sellFee<0||sellFee>=100)throw new Error('Use 1–1,000,000 whole shares, prices up to KES 100 million, a positive purchase price, and fees from 0% to below 100%.');
    const cost=quantity*buyPrice*(1+buyFee/100),proceeds=quantity*sellPrice*(1-sellFee/100);
    const result={cost,proceeds,profit:proceeds-cost,breakEven:buyPrice*(1+buyFee/100)/(1-sellFee/100)};if(!Object.values(result).every(Number.isFinite))throw new Error('These values produce an unrepresentable result. Reduce the amounts or fees.');return result;
  },
  yield({price,dividend,quantity}){
    if(![price,dividend].every(Number.isFinite)||price<=0||price>1e8||dividend<0||dividend>1e8||!Number.isSafeInteger(quantity)||quantity<1||quantity>1000000)throw new Error('Use a positive share price and non-negative annual dividend up to KES 100 million, and 1–1,000,000 whole shares.');
    const result={yield:dividend/price*100,income:dividend*quantity};if(!Object.values(result).every(Number.isFinite))throw new Error('These values produce an unrepresentable result. Increase the price or reduce the amounts.');return result;
  },
  growth({initial,monthly,rate,years}){
    if(![initial,monthly,rate,years].every(Number.isFinite)||initial<0||monthly<0||initial>1e8||monthly>1e8||rate< -100||rate>100||!Number.isInteger(years)||years<1||years>50)throw new Error('Enter non-negative amounts, an annual rate from −100% to 100%, and 1–50 whole years.');
    const monthlyRate=Math.pow(1+rate/100,1/12)-1;let value=initial;
    for(let m=0;m<years*12;m++)value=value*(1+monthlyRate)+monthly;
    const contributions=initial+monthly*years*12;
    return {value,contributions,change:value-contributions};
  }
});
