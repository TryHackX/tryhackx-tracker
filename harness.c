#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
typedef uint8_t ot_hash[20];
static int scan_fromhex(unsigned char c){c=(unsigned char)(c-'0');if(c<=9)return c;c=(unsigned char)(c&~0x20);c=(unsigned char)(c-('A'-'0'));if(c<6)return c+10;return -1;}
static const char *gmap; static size_t gmaplen; static int oob;
static unsigned char rd(const char *p){ if(p<gmap||p>=gmap+gmaplen){oob=1;return 'A';} return (unsigned char)*p; }
static size_t run(const char *map,size_t maplen,int newlogic){
  ot_hash list[64]; ot_hash *info_hash=list; const char *map_end,*read_offs; gmap=map;gmaplen=maplen;oob=0;
  map_end=map+maplen-40; read_offs=map;
  while(read_offs<=map_end){ size_t i;
    for(i=0;i<sizeof(ot_hash);++i){int e1=scan_fromhex(rd(read_offs+2*i));int e2=scan_fromhex(rd(read_offs+1+2*i));if(e1<0||e2<0)break;(*info_hash)[i]=(uint8_t)(e1*16+e2);}
    if(i==sizeof(ot_hash)){ read_offs+=40;
      if(newlogic){ if(read_offs==map+maplen||scan_fromhex(rd(read_offs))<0)++info_hash; }
      else        { if(read_offs==map_end ||scan_fromhex(rd(read_offs))<0)++info_hash; }
    }
    while(read_offs<=map_end && rd(read_offs++)!='\n');
  }
  return info_hash-list;
}
int main(){
  const char *H="0123456789abcdef0123456789abcdef01234567";
  struct {const char*name; char buf[256];} t[8]; int n=0;
  #define T(nm,s) strcpy(t[n].buf,s); t[n].name=nm; n++;
  T("40hex no NL", H);
  T("40hex + NL", "0123456789abcdef0123456789abcdef01234567\n");
  T("two lines, last no NL", "0123456789abcdef0123456789abcdef01234567\n0123456789abcdef0123456789abcdef01234567");
  T("80hex no NL", "0123456789abcdef0123456789abcdef012345670123456789abcdef0123456789abcdef01234567");
  T("80hex + NL", "0123456789abcdef0123456789abcdef012345670123456789abcdef0123456789abcdef01234567\n");
  T("41hex no NL", "0123456789abcdef0123456789abcdef012345678");
  T("hash NL then 41hex", "0123456789abcdef0123456789abcdef01234567\n0123456789abcdef0123456789abcdef012345678");
  T("CRLF last line", "0123456789abcdef0123456789abcdef01234567\r\n0123456789abcdef0123456789abcdef01234567\r\n");
  for(int k=0;k<n;k++){ size_t L=strlen(t[k].buf); char *m=malloc(L); memcpy(m,t[k].buf,L);
    size_t o=run(m,L,0); int oo=oob; size_t w=run(m,L,1); int wo=oob;
    printf("%-24s len=%3zu  old=%zu oob=%d   new=%zu oob=%d\n",t[k].name,L,o,oo,w,wo); free(m);}
  return 0;
}
