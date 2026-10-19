import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { color } from './theme';

interface BrandIdentityProps {
  compact?: boolean;
  inverted?: boolean;
}

export function BrandIdentity({ compact = false, inverted = false }: BrandIdentityProps): React.JSX.Element {
  return <View style={[styles.container, compact ? styles.compact : styles.expanded]}
    accessibilityLabel="Nexa Técnico">
    <View style={[styles.imageBox, compact ? styles.imageSmall : styles.imageLarge,
      inverted && styles.imageInverted]}>
      <Image source={require('../../assets/brand-mark.png')}
        style={[styles.image, compact ? styles.imageSmall : styles.imageLarge]}
        resizeMode="contain" accessibilityLabel="Símbolo Nexa Técnico" />
    </View>
    <View style={styles.wordmark}>
      <Text style={[styles.name,compact && styles.nameSmall,inverted&&styles.onDark]}>
        Ne<Text style={styles.accent}>x</Text>a
      </Text>
      <Text style={[styles.label,compact && styles.labelSmall,inverted&&styles.onDark]}>
        Técnico
      </Text>
    </View>
  </View>;
}

const styles = StyleSheet.create({
  container:{flexDirection:'row',alignItems:'center'},
  expanded:{gap:12},
  compact:{gap:8},
  imageBox:{alignItems:'center',justifyContent:'center'},
  imageLarge:{width:62,height:62},
  imageSmall:{width:40,height:40},
  imageInverted:{backgroundColor:'#fff',borderRadius:12},
  image:{},
  wordmark:{flexDirection:'row',alignItems:'baseline',gap:5,flexShrink:1},
  name:{color:color.navy,fontWeight:'900',fontSize:31,letterSpacing:-1.5},
  nameSmall:{fontSize:21,letterSpacing:-1},
  label:{color:color.navy,fontWeight:'500',fontSize:26,letterSpacing:-1},
  labelSmall:{fontSize:18,letterSpacing:-0.7},
  accent:{color:color.brand},
  onDark:{color:color.surface},
});
