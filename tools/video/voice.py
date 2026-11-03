# Génère les voix (un WAV par scène, 22 kHz), puis transcrit avec Whisper et compare au texte (sans voyelles).
import sys, json, re, difflib, sherpa_onnx, soundfile as sf, numpy as np
sys.path.insert(0, '/tmp/psx/tts'); from narration import N
only = set(sys.argv[1:])
M='/tmp/psx/tts/vits-piper-ar_JO-kareem-medium/'
tts=sherpa_onnx.OfflineTts(sherpa_onnx.OfflineTtsConfig(model=sherpa_onnx.OfflineTtsModelConfig(vits=sherpa_onnx.OfflineTtsVitsModelConfig(model=M+'ar_JO-kareem-medium.onnx',tokens=M+'tokens.txt',data_dir=M+'espeak-ng-data', length_scale=1.08, noise_scale=0.6, noise_scale_w=0.7),num_threads=6)))
D='/tmp/psx/tts/sherpa-onnx-whisper-small/'
rec=sherpa_onnx.OfflineRecognizer.from_whisper(encoder=D+'small-encoder.int8.onnx',decoder=D+'small-decoder.int8.onnx',tokens=D+'small-tokens.txt',language='ar',task='transcribe',num_threads=6)
def plain(t):
    t=re.sub('[ً-ْٰـ]','',t); t=re.sub('[إأآٱ]','ا',t); t=t.replace('ة','ه').replace('ى','ي')
    return re.sub(r'[^ء-ي ]+',' ',t).split()
meta=json.load(open('/tmp/psx/tts/meta.json')) if only else {}
for sid,say,cap in N:
    if only and sid not in only: continue
    a=tts.generate(say,sid=0,speed=1.0)
    x=np.array(a.samples,dtype=np.float32); sr=int(a.sample_rate)
    sf.write(f'/tmp/psx/tts/out/{sid}.wav',x,sr,subtype='PCM_16')
    n=int(len(x)*16000/sr); y=np.interp(np.linspace(0,len(x)-1,n),np.arange(len(x)),x).astype(np.float32)
    s=rec.create_stream(); s.accept_waveform(16000,y); rec.decode_stream(s)
    ref=plain(say); hyp=plain(s.result.text)
    score=difflib.SequenceMatcher(None,ref,hyp).ratio()
    meta[sid]={'dur':round(len(x)/sr,2),'score':round(score,2),'asr':s.result.text.strip()}
    print(f'{sid:8} {len(x)/sr:5.1f}s  {score:.2f}  {s.result.text.strip()}')
json.dump(meta,open('/tmp/psx/tts/meta.json','w'),ensure_ascii=False,indent=1)
