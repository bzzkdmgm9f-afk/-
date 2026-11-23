(() => {
  const width = 56;
  const height = 80;
  const middle = height / 2;
  const resolution = 2;
  const positions = [6, 67, 139, 200, 272, 333];
  const sheets = new Map();
  let backplates;

  function surface(w, h) {
    const canvas = document.createElement('canvas');
    canvas.width = w * resolution;
    canvas.height = h * resolution;
    const ctx = canvas.getContext('2d');
    ctx.scale(resolution, resolution);
    return {canvas, ctx};
  }

  function box(ctx, x, y, w, h, r, color) {
    ctx.beginPath();
    ctx.roundRect(x, y, w, h, r);
    ctx.fillStyle = color;
    ctx.fill();
  }

  function backplate() {
    if (backplates) return backplates;
    const {canvas, ctx} = surface(396, 94);
    positions.forEach(x => {
      ctx.save();
      ctx.translate(x, 4);
      box(ctx,-.6,-.6,width+1.2,height+1.2,5,'rgba(185,229,213,.20)');
      ctx.restore();
    });
    backplates = canvas;
    return canvas;
  }

  function sheet(value) {
    if (sheets.has(value)) return sheets.get(value);
    const {canvas, ctx}=surface(width,height);
    const tint=ctx.createLinearGradient(0,0,width,height);
    tint.addColorStop(0,'#284039');
    tint.addColorStop(1,'#233932');
    box(ctx,0,0,width,height,4.5,tint);
    ctx.textAlign='center';
    ctx.textBaseline='middle';
    ctx.font='400 48px Inter, "Helvetica Neue", sans-serif';
    ctx.fillStyle='#d2e7dc';
    ctx.fillText(value,width/2,middle+2,width-9);
    sheets.set(value,canvas);
    return canvas;
  }

  function half(ctx, page, lower) {
    const y=lower?middle:0;
    ctx.drawImage(page,0,y*resolution,width*resolution,middle*resolution,0,y,width,middle);
  }

  function seam(ctx) {
    ctx.fillStyle='rgba(14,34,27,.36)';
    ctx.fillRect(0,middle-.3,width,.6);

  }

  function movingHalf(ctx, face, lower, angle) {
    const lift=Math.sin(angle);
    const shade=lower?.15:.24;
    const step=2;
    function project(r) {
      const fraction=r/middle;
      const curve=.08*fraction*Math.sin(2*angle);
      const theta=angle+curve;
      const z=r*Math.sin(theta);
      const scale=325/(325-z);
      return {y:middle+(-r*Math.cos(theta)+z*.09)*scale, w:width*scale, z};
    }
    const tip=project(middle);
    // A broad occlusion follows the free edge across the next leaf. It grows
    // softer and slips to the right as the flap rises from the paper below.
    ctx.save();
    ctx.beginPath();
    ctx.rect(-3,-3,width+6,height+7);
    ctx.clip();
    const shadowY=middle-middle*Math.cos(angle)+tip.z*.53;
    ctx.shadowBlur=1+lift*5;
    ctx.shadowColor='rgba(5,14,8,'+(.17+.13*lift)+')';
    ctx.fillStyle='rgba(4,13,7,'+(.11+.20*lift)+')';
    ctx.beginPath();
    ctx.moveTo(-1,middle);
    ctx.lineTo(width,middle);
    ctx.lineTo(width+tip.z*.15,shadowY);
    ctx.lineTo(tip.z*.15,shadowY);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
    for(let near=0;near<middle;near+=step){
      const far=Math.min(near+step,middle);
      const a=project(near),b=project(far);
      const w=(a.w+b.w)/2;
      const top=Math.min(a.y,b.y);
      const h=Math.abs(b.y-a.y);
      if(h<.016) continue;
      const sourceY=lower?middle+near:middle-far;
      ctx.drawImage(face,0,sourceY*resolution,width*resolution,(far-near)*resolution,
        (width-w)/2,top,w,h+.32);
      ctx.fillStyle='rgba(18,27,19,'+(lift*(.10+shade*near/middle))+')';
      ctx.fillRect((width-w)/2,top,w,h+.32);
    }
    const thick=.25+lift*.65;
    const edgeLight = ctx.createLinearGradient(0,tip.y-thick/2,0,tip.y+thick);
    edgeLight.addColorStop(0,'rgba(200,199,163,'+(.24+.42*lift)+')');
    edgeLight.addColorStop(.46,'rgba(139,148,121,'+(.16+.51*lift)+')');
    edgeLight.addColorStop(1,'rgba(23,33,23,'+(.22+.44*lift)+')');
    ctx.fillStyle=edgeLight;
    ctx.fillRect((width-tip.w)/2,tip.y-thick*.5,tip.w,thick);
  }

  function fallAngle(p) {
    // Released by a cam, the leaf accelerates and taps its stop. Two
    // progressively smaller rebounds keep the final impact restrained.
    if (p<.82) {
      const t=p/.82;
      return Math.PI*t*t*(1.80-.80*t);
    }
    const rebound=(p-.82)/.18;
    return Math.PI-.118*Math.abs(Math.sin(2*Math.PI*rebound))*(1-rebound)*(1-rebound);
  }

  function tile(ctx,x,old,value,p){
    ctx.save();
    ctx.translate(x,4);
    const next=sheet(value);
    if(old===value || p>=1 || p<=0){
      ctx.drawImage(p<=0?sheet(old):next,0,0,width,height);
      seam(ctx);
      ctx.restore();
      return;
    }
    const before=sheet(old);
    half(ctx,next,false);
    half(ctx,before,true);
    const angle=fallAngle(p);
    movingHalf(ctx,angle<Math.PI/2?before:next,angle>=Math.PI/2,angle);
    seam(ctx);
    ctx.restore();
  }

  window.paperClocks=window.paperClocks||{};
  window.paperClocks.flap=(ctx,previous,current,p)=>{
    ctx.drawImage(backplate(),0,0,396,94);
    positions.forEach((x,i)=>tile(ctx,x,previous[i],current[i],p));
  };
})();
