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
import { BrandIdentity } from './brand/BrandIdentity';
import { color } from './brand/theme';
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
    <ScrollView contentContainerStyle={styles.loginScroll} keyboardShouldPersistTaps="handled">
      <View style={styles.loginHero}>
        <BrandIdentity />
        <Text style={styles.eyebrow}>CENTRAL DE SERVIÇOS EM CAMPO</Text>
        <Text style={styles.loginHeadline}>Conectado ao seu próximo atendimento.</Text>
        <Text style={styles.subtitle}>Chamados, peças e relatórios na palma da sua mão.</Text>
      </View>
      <View style={styles.loginCard}>
        <Text style={styles.sectionTitle}>Entrar no Nexa Técnico</Text>
        <Text style={styles.subtitle}>Utilize o acesso fornecido pela sua empresa.</Text>
        <Field label="E-mail" value={email} onChangeText={setEmail}/>
        <Field label="Senha" value={password} onChangeText={setPassword} secureTextEntry/>
        <Field label="Empresa (identificador)" value={tenant} onChangeText={setTenant} placeholder="slug-da-empresa"/>
        <Action title="Acessar meus chamados  →" onPress={()=>void submitLogin()}
          disabled={busy||!email||!password||!tenant}/>
      </View>
      <Text style={styles.loginFooter}>NEXA SYSTEM  •  ATENDIMENTO TÉCNICO</Text>
    </ScrollView>
  </KeyboardAvoidingView>;
  else if(screen==='tickets')content=<View style={{flex:1}}>
    <View style={styles.brandBar}>
      <BrandIdentity compact/>
      <Action title="Sair" subtle onPress={()=>void exit()}/>
    </View>
    <View style={styles.dashboardHeader}>
      <View style={{flex:1}}>
        <Text style={styles.eyebrow}>PAINEL DO TÉCNICO</Text>
        <Text style={styles.title}>Meus chamados</Text>
        <Text style={styles.subtitle}>Atendimentos atribuídos à sua conta</Text>
      </View>
      <View style={styles.countBubble}>
        <Text style={styles.countNumber}>{list.length}</Text>
        <Text style={styles.countCaption}>carregados</Text>
      </View>
    </View>
    <Action title="↻  Atualizar lista" subtle disabled={busy} onPress={()=>void run(refresh)}/>
    <FlatList data={list} keyExtractor={item=>String(item.id)} refreshing={busy}
      contentContainerStyle={styles.ticketList} showsVerticalScrollIndicator={false}
      onRefresh={()=>void run(refresh)} onEndReached={()=>void loadMore()} onEndReachedThreshold={0.4}
      ListEmptyComponent={<View style={styles.emptyState}>
        <Text style={styles.emptyHeading}>Tudo em ordem por enquanto</Text>
        <Text style={styles.empty}>Quando o administrativo atribuir um chamado, ele aparecerá aqui.</Text>
      </View>}
      renderItem={({item})=><Pressable accessibilityRole="button"
        accessibilityLabel={`Abrir chamado ${item.id}, ${item.subject}`}
        onPress={()=>void openTicket(item.id)} style={styles.card}>
        <View style={styles.ticketCardTop}>
          <Text style={styles.cardKicker}>CHAMADO #{item.id}</Text>
          <View style={styles.statusChip}>
            <Text style={styles.statusChipText}>{statusLabel(item.status)}</Text>
          </View>
        </View>
        <Text style={styles.cardTitle}>{item.subject}</Text>
        <Text style={styles.meta}>{item.customer?.name??'Atendimento interno'}</Text>
        <View style={styles.ticketCardBottom}>
          <Text style={styles.cardPriority}>Prioridade: {priorityLabel(item.priority)}</Text>
          <Text style={styles.cardLink}>Ver atendimento  →</Text>
        </View>
      </Pressable>}/>
  </View>;
  else if(!current)content=<Text>Chamado não encontrado.</Text>;
  else if(screen==='detail')content=<ScrollView contentContainerStyle={styles.scroll}
    showsVerticalScrollIndicator={false}>
    <Text style={styles.eyebrow}>ORDEM DE SERVIÇO  /  #{current.id}</Text>
    <View style={styles.detailHero}>
      <View style={styles.ticketCardTop}>
        <Text style={[styles.cardKicker,styles.onDarkMeta]}>ATENDIMENTO TÉCNICO</Text>
        <View style={styles.statusChip}><Text style={styles.statusChipText}>{statusLabel(current.status)}</Text></View>
      </View>
      <Text style={[styles.title,styles.onDarkTitle]}>{current.subject}</Text>
      <Text style={[styles.meta,styles.onDarkMeta]}>Prioridade {priorityLabel(current.priority)}</Text>
    </View>
    <View style={styles.panel}>
      <Text style={styles.sectionTitle}>Informações do atendimento</Text>
      <Text style={styles.label}>Cliente</Text>
      <Text style={styles.infoValue}>{current.customer?.name??'Atendimento interno'}</Text>
      <Text style={styles.label}>Equipamento</Text>
      <Text style={styles.infoValue}>{current.printer ? `${current.printer.manufacturer} ${current.printer.model}` : 'Não informado'}</Text>
      {!!current.printer?.ip_address && <Text style={styles.meta}>IP: {current.printer.ip_address}</Text>}
      <Text style={styles.label}>Descrição do problema</Text>
      <Text style={styles.infoValue}>{current.description}</Text>
    </View>
    {['triage','pending','unassigned'].includes(current.status) &&
      <Action title="Iniciar atendimento" disabled={busy} onPress={()=>void start()}/>}
    {!['closed','resolved'].includes(current.status) &&
      <Action title="Solicitar peças" disabled={busy} onPress={()=>void browseParts()}/>}
    <View style={styles.panel}>
      <Text style={styles.sectionTitle}>Peças solicitadas</Text>
      {current.part_requests?.length ? current.part_requests.map(part=>
        <View key={part.id} style={styles.partRow}>
          <Text style={styles.infoValue}>{part.quantity}× {part.item?.name??`Item #${part.stock_item_id}`}</Text>
          <Text style={styles.meta}>{partStatus(part.status)}</Text>
        </View>) : <Text style={styles.meta}>Nenhuma solicitação registrada.</Text>}
    </View>
    {current.status==='in_progress' &&
      <Action title="Serviço concluído — coletar assinatura" disabled={busy}
        onPress={()=>{setStrokes([]);setScreen('signature')}}/>}
    {current.signature && <View style={styles.signedPanel}>
      <Text style={styles.signedLabel}>✓ ATENDIMENTO ASSINADO</Text>
      <Text style={styles.cardTitle}>{current.signature.signer_name}</Text>
      <Text style={styles.meta}>{new Date(current.signature.signed_at).toLocaleString('pt-BR')}</Text>
      <Text style={styles.infoValue}>{current.signature.service_summary}</Text>
    </View>}
  </ScrollView>;
  else if(screen==='parts')content=<ScrollView contentContainerStyle={styles.scroll}
    showsVerticalScrollIndicator={false}>
    <Text style={styles.eyebrow}>ESTOQUE  /  SOLICITAÇÃO</Text>
    <Text style={styles.title}>Solicitar peça</Text>
    <Text style={styles.subtitle}>Escolha o material necessário para o reparo. A liberação será avaliada pelo administrativo.</Text>
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
  else content=<ScrollView contentContainerStyle={styles.scroll}
    showsVerticalScrollIndicator={false}>
    <Text style={styles.eyebrow}>FINALIZAÇÃO  /  ACEITE</Text>
    <Text style={styles.title}>Assinatura do cliente</Text>
    <Text style={styles.subtitle}>Registre o serviço e peça ao responsável pelo equipamento que confirme o atendimento com a assinatura.</Text>
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
    <StatusBar barStyle="dark-content" backgroundColor={color.bg}/>
    {authenticated && screen!=='tickets' && <View style={styles.navBar}>
      <Action title="← Voltar" subtle onPress={navigateBack}/>
      <BrandIdentity compact/>
    </View>}
    {error.length>0&&<Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
    {busy&&<ActivityIndicator color={color.brand}/>}
    {content}
  </SafeAreaView></SafeAreaProvider>;
}

function priorityLabel(priority:string):string {
  return ({low:'baixa',normal:'normal',high:'alta',critical:'crítica'} as Record<string,string>)[priority]??priority;
}
function partStatus(status:string):string {
  return ({pending:'Aguardando aprovação',approved:'Aprovada',rejected:'Rejeitada'} as Record<string,string>)[status]??status;
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
  login:{flex:1},
  loginScroll:{flexGrow:1,justifyContent:'center',paddingVertical:28,gap:22},
  loginHero:{paddingHorizontal:12,gap:9,paddingVertical:6},
  loginHeadline:{color:color.navy,fontSize:28,fontWeight:'800',lineHeight:34,letterSpacing:-0.7,maxWidth:330,marginTop:5},
  loginCard:{backgroundColor:color.surface,borderRadius:24,padding:22,
    borderWidth:1,borderColor:color.border,shadowColor:color.navy,shadowOpacity:0.07,
    shadowRadius:16,shadowOffset:{width:0,height:7},elevation:3,gap:3},
  loginFooter:{textAlign:'center',color:color.faint,fontSize:10,fontWeight:'700',letterSpacing:1.4},
  eyebrow:{color:color.brandDark,fontSize:11,fontWeight:'800',letterSpacing:1.6,marginTop:8},
  scroll:{paddingBottom:36,gap:14,paddingTop:12},
  title:{fontSize:27,fontWeight:'800',color:color.navy,letterSpacing:-0.7,marginVertical:5},
  subtitle:{fontSize:14,color:color.muted,lineHeight:21,marginBottom:5},
  sectionTitle:{color:color.navy,fontSize:18,fontWeight:'800',letterSpacing:-0.4,marginBottom:4},
  field:{marginVertical:8,gap:7},
  label:{fontSize:13,fontWeight:'700',color:color.ink,marginTop:8},
  input:{backgroundColor:color.surface,borderWidth:1,borderColor:color.border,
    borderRadius:12,paddingHorizontal:14,paddingVertical:12,fontSize:16,color:color.ink,minHeight:49},
  button:{backgroundColor:color.brand,borderRadius:12,paddingVertical:13,paddingHorizontal:16,
    alignItems:'center',justifyContent:'center',minHeight:48,marginVertical:5},
  buttonSubtle:{backgroundColor:color.softBlue},
  buttonText:{color:color.surface,fontSize:15,fontWeight:'800'},
  brandBar:{flexDirection:'row',alignItems:'center',justifyContent:'space-between',
    paddingVertical:12,borderBottomColor:color.line,borderBottomWidth:1},
  navBar:{flexDirection:'row',justifyContent:'space-between',alignItems:'center',
    borderBottomWidth:1,borderBottomColor:color.line,paddingVertical:6},
  dashboardHeader:{flexDirection:'row',alignItems:'center',justifyContent:'space-between',paddingTop:15,paddingBottom:8,gap:12},
  countBubble:{minWidth:78,backgroundColor:color.navy,borderRadius:16,
    paddingVertical:10,paddingHorizontal:9,alignItems:'center',justifyContent:'center'},
  countNumber:{color:color.surface,fontSize:24,fontWeight:'900'},
  countCaption:{fontSize:10,fontWeight:'600',color:'#CDE5FF'},
  ticketList:{paddingTop:4,paddingBottom:28,flexGrow:1},
  card:{backgroundColor:color.surface,borderRadius:17,padding:17,marginVertical:6,
    gap:9,borderWidth:1,borderColor:color.border,shadowColor:color.navy,
    shadowOpacity:0.035,shadowRadius:9,shadowOffset:{width:0,height:3},elevation:1},
  ticketCardTop:{flexDirection:'row',justifyContent:'space-between',alignItems:'center',gap:12},
  ticketCardBottom:{flexDirection:'row',justifyContent:'space-between',alignItems:'center',
    borderTopWidth:1,borderTopColor:color.line,paddingTop:12,marginTop:5},
  cardKicker:{fontSize:10,color:color.brandDark,letterSpacing:1.2,fontWeight:'800'},
  cardTitle:{fontWeight:'800',fontSize:16,color:color.ink,lineHeight:23},
  meta:{fontSize:13,color:color.muted,lineHeight:20,marginTop:2},
  statusChip:{backgroundColor:color.softBlue,paddingVertical:6,paddingHorizontal:9,
    borderRadius:999,maxWidth:170},
  statusChipText:{fontSize:11,fontWeight:'700',color:color.brandDark},
  cardPriority:{fontSize:12,color:color.muted,fontWeight:'600'},
  cardLink:{fontSize:12,fontWeight:'800',color:color.brand},
  emptyState:{backgroundColor:color.surface,padding:26,borderRadius:18,
    marginTop:15,borderWidth:1,borderColor:color.border},
  emptyHeading:{textAlign:'center',color:color.ink,fontSize:16,fontWeight:'800'},
  empty:{textAlign:'center',paddingTop:12,color:color.muted,lineHeight:22},
  detailHero:{backgroundColor:color.navy,borderRadius:18,padding:20,gap:8},
  onDarkTitle:{color:color.surface},
  onDarkMeta:{color:'#C5E5FF'},
  panel:{backgroundColor:color.surface,borderRadius:17,padding:18,gap:8,
    borderColor:color.border,borderWidth:1},
  infoValue:{fontSize:14,color:color.ink,lineHeight:22},
  partRow:{borderTopColor:color.line,borderTopWidth:1,paddingTop:11,gap:4},
  signedPanel:{backgroundColor:color.positiveBg,borderColor:'#BCE6D0',
    borderWidth:1,padding:17,borderRadius:16,gap:7},
  signedLabel:{fontSize:12,color:color.positive,fontWeight:'800',letterSpacing:1.0},
  item:{backgroundColor:color.surface,borderRadius:12,borderWidth:1,
    borderColor:color.border,padding:14,marginVertical:4},
  selected:{borderColor:color.brand,borderWidth:2,backgroundColor:color.softBlue},
  pad:{height:180,backgroundColor:color.surface,borderRadius:12,
    borderWidth:1,borderColor:color.border,overflow:'hidden'},
  checkbox:{flexDirection:'row',gap:10,alignItems:'center',marginVertical:12,
    backgroundColor:color.surface,borderColor:color.border,borderWidth:1,padding:12,borderRadius:12},
  error:{color:color.danger,backgroundColor:color.dangerBg,
    padding:12,marginVertical:9,borderRadius:11},
});
