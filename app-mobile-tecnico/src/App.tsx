import React, { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator, Alert, FlatList, KeyboardAvoidingView, Platform, Pressable,
  ScrollView, StatusBar, StyleSheet, Switch, Text, TextInput, View,
  type GestureResponderEvent,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';
import ViewShot from 'react-native-view-shot';
import * as api from './api';
import type { StockItem, Ticket } from './types';

const color = { ink: '#182235', muted: '#5b687d', brand: '#1763bd', bg: '#f4f7fb', border: '#dce3ed' };
type Screen = 'tickets'|'detail'|'parts'|'signature';

function Action({ title, onPress, disabled=false, subtle=false }: {
  title:string; onPress:()=>void; disabled?:boolean; subtle?:boolean;
}) {
  return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress}
    style={[styles.button, subtle && styles.buttonSubtle, disabled && {opacity:0.45}]}>
    <Text style={[styles.buttonText,subtle && {color:color.brand}]}>{title}</Text>
  </Pressable>;
}
function Field({label,value,onChangeText,secureTextEntry=false,multiline=false,placeholder}: {
  label:string; value:string; onChangeText:(v:string)=>void; secureTextEntry?:boolean;
  multiline?:boolean; placeholder?:string;
}) {
  return <View style={styles.field}>
    <Text style={styles.label}>{label}</Text>
    <TextInput accessibilityLabel={label} style={[styles.input,multiline && {height:105,textAlignVertical:'top' as const}]}
      value={value} onChangeText={onChangeText} autoCapitalize="none"
      multiline={multiline} secureTextEntry={secureTextEntry} placeholder={placeholder}
      placeholderTextColor="#758096" />
  </View>;
}

export default function App(): React.JSX.Element {
  const [authenticated,setAuthenticated] = useState(false);
  const [initializing,setInitializing] = useState(true);
  const [busy,setBusy] = useState(false);
  const [error,setError] = useState('');
  const [email,setEmail] = useState('');
  const [password,setPassword] = useState('');
  const [tenant,setTenant] = useState('');
  const [screen,setScreen] = useState<Screen>('tickets');
  const [list,setList] = useState<Ticket[]>([]);
  const [ticketPage,setTicketPage] = useState(1);
  const [lastTicketPage,setLastTicketPage] = useState(1);
  const loadingNext=useRef(false);
  const [current,setCurrent] = useState<Ticket | null>(null);
  const [items,setItems] = useState<StockItem[]>([]);
  const [search,setSearch] = useState('');
  const [selected,setSelected] = useState<StockItem | null>(null);
  const [qty,setQty] = useState('1');
  const [partReason,setPartReason] = useState('');
  const [signer,setSigner] = useState('');
  const [summary,setSummary] = useState('');
  const [consent,setConsent] = useState(false);
  const [strokes,setStrokes] = useState<string[]>([]);
  const [padWidth,setPadWidth] = useState(320);
  const shot=useRef<ViewShot>(null);
  const partRequestId=useRef<string|null>(null);
  const activeStrokeIndex=useRef(-1);

  useEffect(() => {
    let active=true;
    api.restoreSession().then(ok=>{if(active)setAuthenticated(ok)})
      .finally(()=>{if(active)setInitializing(false)});
    return ()=>{active=false};
  },[]);

  useEffect(()=>{
    if (!authenticated) return;
    let alive=true;
    api.tickets().then(result=>{if(alive){
      setList(result.data);setTicketPage(1);setLastTicketPage(result.meta?.last_page??1);
    }}).catch(e=>{if(alive)setError(String(e instanceof Error ? e.message:e))});
    return ()=>{alive=false};
  },[authenticated]);

  async function run(work:()=>Promise<void>):Promise<void> {
    setError('');setBusy(true);
    try { await work(); }
    catch(e) {
      if(e instanceof api.ApiError && e.status===401) {
        setAuthenticated(false);
        setCurrent(null);
      }
      setError(e instanceof Error?e.message:'Erro inesperado.');
    } finally {setBusy(false)}
  }
  async function refresh():Promise<void> {
    const result=await api.tickets();
    setList(result.data);setTicketPage(1);setLastTicketPage(result.meta?.last_page??1);
  }
  async function loadMore():Promise<void> {
    if(loadingNext.current||busy||ticketPage>=lastTicketPage)return;
    loadingNext.current=true;
    try {
      const page=await api.tickets(ticketPage+1);
      setList(previous=>previous.concat(page.data.filter(item=>!previous.some(t=>t.id===item.id))));
      setTicketPage(ticketPage+1);
      setLastTicketPage(page.meta?.last_page??lastTicketPage);
    } catch(e) {
      setError(e instanceof Error?e.message:'Erro ao carregar mais chamados.');
    } finally {loadingNext.current=false}
  }
  async function openTicket(id:number):Promise<void> {
    await run(async()=>{setCurrent(await api.ticket(id));partRequestId.current=null;setScreen('detail')});
  }
  async function submitLogin():Promise<void> {
    await run(async()=>{await api.login(email.trim(),password,tenant.trim());setPassword('');setAuthenticated(true);setScreen('tickets')});
  }
  async function exit():Promise<void> {
    await run(async()=>{await api.logout();setAuthenticated(false);setCurrent(null);setList([])});
  }
  async function start():Promise<void> {
    if(!current)return;
    await run(async()=>{setCurrent(await api.startTicket(current.id));await refresh()});
  }
  async function browseParts():Promise<void> {
    await run(async()=>{setItems(await api.stockItems(''));setScreen('parts')});
  }
  async function searchParts():Promise<void> {
    await run(async()=>{setItems(await api.stockItems(search))});
  }
  async function sendPart():Promise<void> {
    if(!current||!selected)return;
    const quantity=Number(qty);
    if(!Number.isInteger(quantity)||quantity<1||quantity>1000){setError('Quantidade inválida.');return}
    if(partReason.trim().length<5){setError('Explique por que a peça é necessária.');return}
    const request_id=partRequestId.current??makeUuid();
    partRequestId.current=request_id;
    await run(async()=>{
      await api.requestPart(current.id,{stock_item_id:selected.id,quantity,reason:partReason.trim(),request_id});
      setCurrent(await api.ticket(current.id));
      partRequestId.current=null;
      setSelected(null);setPartReason('');setQty('1');setScreen('detail');
      Alert.alert('Solicitação enviada','O setor responsável poderá analisar e autorizar a peça.');
    });
  }
  function point(e:GestureResponderEvent):string {
    const {locationX,locationY}=e.nativeEvent;
    return `${Math.round(Math.max(0,Math.min(padWidth,locationX)))} ${Math.round(Math.max(0,Math.min(180,locationY)))}`;
  }
  function beginStroke(e:GestureResponderEvent):void {
    activeStrokeIndex.current=strokes.length;
    setStrokes(current=>[...current,'M '+point(e)]);
  }
  function extendStroke(e:GestureResponderEvent):void {
    const active=activeStrokeIndex.current;
    if(active<0)return;
    setStrokes(current=>current.map((stroke,index)=>index===active?stroke+' L '+point(e):stroke));
  }
  async function submitSignature():Promise<void> {
    if(!current)return;
    if(!strokes.some(path=>path.includes(' L '))||signer.trim().length<3||summary.trim().length<10||!consent){
      setError('Informe o serviço realizado, o nome do cliente, a assinatura e a confirmação de aceite.');
      return;
    }
    await run(async()=>{
      const signature_png_base64=await shot.current?.capture?.();
      if(!signature_png_base64)throw new Error('Não foi possível capturar a assinatura.');
      setCurrent(await api.signTicket(current.id,{
        signer_name:signer.trim(),service_summary:summary.trim(),signature_png_base64,consent:true,
      }));
      setStrokes([]);setSigner('');setSummary('');setConsent(false);
      setScreen('detail');await refresh();
      Alert.alert('Atendimento assinado','O chamado passou para resolvido e aguarda encerramento administrativo.');
    });
  }
  function navigateBack():void {
    setError('');
    if(screen==='parts'||screen==='signature'){setScreen('detail');return}
    setCurrent(null);setScreen('tickets');
  }

  let content:React.ReactNode;
  if(initializing)content=<ActivityIndicator size="large" color={color.brand} style={{marginTop:70}} />;
  else if(!authenticated)content=<KeyboardAvoidingView behavior={Platform.OS==='ios'?'padding':undefined} style={styles.login}>
    <Text style={styles.title}>Nexa Técnico</Text>
    <Text style={styles.subtitle}>Acesso exclusivo para equipe técnica</Text>
    <Field label="E-mail" value={email} onChangeText={setEmail}/>
    <Field label="Senha" value={password} onChangeText={setPassword} secureTextEntry/>
    <Field label="Empresa (identificador)" value={tenant} onChangeText={setTenant} placeholder="slug-da-empresa"/>
    <Action title="Entrar" onPress={()=>void submitLogin()} disabled={busy||!email||!password||!tenant}/>
  </KeyboardAvoidingView>;
  else if(screen==='tickets')content=<View style={{flex:1}}>
    <View style={styles.header}><Text style={styles.title}>Meus chamados</Text>
      <Action title="Sair" subtle onPress={()=>void exit()}/></View>
    <Action title="Atualizar lista" subtle disabled={busy} onPress={()=>void run(refresh)}/>
    <FlatList data={list} keyExtractor={item=>String(item.id)} refreshing={busy}
      onRefresh={()=>void run(refresh)} onEndReached={()=>void loadMore()} onEndReachedThreshold={0.4}
      ListEmptyComponent={<Text style={styles.empty}>Nenhum chamado atribuído.</Text>}
      renderItem={({item})=><Pressable accessibilityRole="button"
        onPress={()=>void openTicket(item.id)} style={styles.card}>
        <Text style={styles.cardTitle}>#{item.id} · {item.subject}</Text>
        <Text style={styles.meta}>{item.customer?.name??'Atendimento interno'} · {item.priority.toUpperCase()}</Text>
        <Text style={styles.meta}>Status: {statusLabel(item.status)}</Text>
      </Pressable>}/>
  </View>;
  else if(!current)content=<Text>Chamado não encontrado.</Text>;
  else if(screen==='detail')content=<ScrollView contentContainerStyle={styles.scroll}>
    <Text style={styles.title}>Chamado #{current.id}</Text>
    <Text style={styles.cardTitle}>{current.subject}</Text>
    <Text style={styles.meta}>Status: {statusLabel(current.status)} · Prioridade: {current.priority}</Text>
    <Text style={styles.label}>Cliente</Text><Text>{current.customer?.name??'Atendimento interno'}</Text>
    <Text style={styles.label}>Equipamento</Text>
    <Text>{current.printer ? `${current.printer.manufacturer} ${current.printer.model}` : 'Não informado'}</Text>
    {!!current.printer?.ip_address && <Text style={styles.meta}>IP: {current.printer.ip_address}</Text>}
    <Text style={styles.label}>Descrição</Text><Text>{current.description}</Text>
    {['triage','pending','unassigned'].includes(current.status) &&
      <Action title="Iniciar atendimento" disabled={busy} onPress={()=>void start()}/>}
    {!['closed','resolved'].includes(current.status) &&
      <Action title="Solicitar peças" disabled={busy} onPress={()=>void browseParts()}/>}
    <Text style={styles.label}>Peças solicitadas</Text>
    {current.part_requests?.length ? current.part_requests.map(part=>
      <Text key={part.id} style={styles.meta}>
        {part.quantity}× {part.item?.name??`Item #${part.stock_item_id}`} · {part.status}
      </Text>) : <Text style={styles.meta}>Nenhuma solicitação.</Text>}
    {current.status==='in_progress' &&
      <Action title="Serviço concluído — coletar assinatura" disabled={busy}
        onPress={()=>{setStrokes([]);setScreen('signature')}}/>}
    {current.signature && <View style={styles.card}>
      <Text style={styles.cardTitle}>Assinado por {current.signature.signer_name}</Text>
      <Text style={styles.meta}>{new Date(current.signature.signed_at).toLocaleString('pt-BR')}</Text>
      <Text>{current.signature.service_summary}</Text>
    </View>}
  </ScrollView>;
  else if(screen==='parts')content=<ScrollView contentContainerStyle={styles.scroll}>
    <Text style={styles.title}>Solicitar peça</Text>
    <Field label="Pesquisar peça por nome ou código" value={search} onChangeText={setSearch}/>
    <Action title="Pesquisar" subtle disabled={busy} onPress={()=>void searchParts()}/>
    {items.map(item=><Pressable key={item.id} accessibilityRole="button"
      onPress={()=>{partRequestId.current=null;setSelected(item)}} style={[styles.item,selected?.id===item.id&&styles.selected]}>
      <Text style={styles.cardTitle}>{item.name}</Text><Text style={styles.meta}>{item.sku}</Text>
    </Pressable>)}
    {selected && <>
      <Text style={styles.label}>Selecionada: {selected.name}</Text>
      <Field label="Quantidade" value={qty} onChangeText={value=>{setQty(value);partRequestId.current=null}}/>
      <Field label="Motivo da solicitação" value={partReason} onChangeText={value=>{setPartReason(value);partRequestId.current=null}} multiline/>
      <Action title="Enviar solicitação de peça" disabled={busy} onPress={()=>void sendPart()}/>
    </>}
  </ScrollView>;
  else content=<ScrollView contentContainerStyle={styles.scroll}>
    <Text style={styles.title}>Assinatura do cliente</Text>
    <Text style={styles.subtitle}>Registre o reparo e peça ao responsável pelo equipamento para assinar na tela.</Text>
    <Field label="Serviço realizado e diagnóstico" value={summary} onChangeText={setSummary} multiline/>
    <Field label="Nome completo de quem assina" value={signer} onChangeText={setSigner}/>
    <Text style={styles.label}>Assine abaixo</Text>
    <ViewShot ref={shot} options={{format:'png',result:'base64',quality:1}}>
      <View onLayout={e=>setPadWidth(e.nativeEvent.layout.width)} style={styles.pad}
        onStartShouldSetResponder={()=>true} onMoveShouldSetResponder={()=>true}
        onResponderGrant={beginStroke} onResponderMove={extendStroke}
        onResponderRelease={()=>{activeStrokeIndex.current=-1}}>
        <Svg width="100%" height={180} viewBox={`0 0 ${padWidth} 180`}>
          {strokes.map((d,i)=><Path key={i} d={d} stroke="#101829" strokeWidth={2.7}
            strokeLinecap="round" strokeLinejoin="round" fill="none"/>)}
        </Svg>
      </View>
    </ViewShot>
    <Action title="Limpar assinatura" subtle onPress={()=>setStrokes([])}/>
    <View style={styles.checkbox}><Switch accessibilityLabel="Confirmo o aceite do cliente"
      value={consent} onValueChange={setConsent}/>
      <Text style={{flex:1,color:color.ink}}>O cliente confirma a realização do serviço descrito e autoriza o registro da assinatura neste chamado.</Text>
    </View>
    <Action title="Salvar assinatura e resolver chamado" disabled={busy} onPress={()=>void submitSignature()}/>
    <Text style={styles.meta}>Assinatura eletrônica simples; não equivale automaticamente a certificado digital ICP-Brasil.</Text>
  </ScrollView>;

  return <SafeAreaProvider><SafeAreaView style={styles.safe}>
    <StatusBar barStyle="dark-content"/>
    {authenticated && screen!=='tickets' && <Action title="← Voltar" subtle onPress={navigateBack}/>}
    {error.length>0&&<Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
    {busy&&<ActivityIndicator color={color.brand}/>}
    {content}
  </SafeAreaView></SafeAreaProvider>;
}

function statusLabel(s:Ticket['status']):string {
  return ({triage:'Triagem',unassigned:'Sem responsável',pending:'Pendente',
    in_progress:'Em atendimento',resolved:'Resolvido',closed:'Encerrado'})[s];
}

function makeUuid():string {
  // Random identifier for idempotent requests; not a cryptographic credential.
  const chars='xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
  return chars.replace(/[xy]/g,c=>{
    const r=Math.floor(Math.random()*16);return (c==='x'?r:(r&3|8)).toString(16);
  });
}

const styles=StyleSheet.create({
  safe:{flex:1,backgroundColor:color.bg,paddingHorizontal:18},
  login:{flex:1,justifyContent:'center',gap:8},
  scroll:{paddingBottom:35,gap:12,paddingTop:12},
  title:{fontSize:25,fontWeight:'700',color:color.ink,marginVertical:10},
  subtitle:{fontSize:14,color:color.muted,marginBottom:8},
  field:{marginVertical:7,gap:6},
  label:{fontWeight:'700',color:color.ink,marginTop:8},
  input:{backgroundColor:'#fff',borderWidth:1,borderColor:color.border,
    borderRadius:10,paddingHorizontal:12,paddingVertical:11,fontSize:16,color:color.ink},
  button:{backgroundColor:color.brand,borderRadius:10,padding:12,alignItems:'center',marginVertical:5},
  buttonSubtle:{backgroundColor:'#e6effb'},
  buttonText:{color:'#fff',fontSize:15,fontWeight:'700'},
  header:{flexDirection:'row',alignItems:'center',justifyContent:'space-between'},
  card:{backgroundColor:'#fff',borderRadius:12,padding:16,marginVertical:6,gap:5,borderWidth:1,borderColor:color.border},
  cardTitle:{fontWeight:'700',fontSize:16,color:color.ink},
  meta:{fontSize:13,color:color.muted,marginTop:4},
  empty:{textAlign:'center',padding:28,color:color.muted},
  item:{backgroundColor:'#fff',borderRadius:10,borderWidth:1,borderColor:color.border,padding:12,marginVertical:3},
  selected:{borderColor:color.brand,borderWidth:2},
  pad:{height:180,backgroundColor:'#fff',borderRadius:8,borderWidth:1,borderColor:color.border,overflow:'hidden'},
  checkbox:{flexDirection:'row',gap:8,alignItems:'center',marginVertical:10},
  error:{color:'#a81725',backgroundColor:'#ffebed',padding:10,marginVertical:8,borderRadius:8},
});
