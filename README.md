# Ohjelmakoodin kirjoitus

Tee php-ohjelma joka käyttää luokkarakennetta. Ohjelman tulee tuottaa html-koodia.
Ohjelmalle annetaan GET-paremetrinä luku ja ohjelma generoi luvun mukaisen määrän
sisäkkäisiä div-elementtejä. Aseta elementeille tyylimäärityksiä niin, että 
  - elementeillä on reunaviivat
  - elementtien reunojen välissä on tyhjää tilaa
  - joka toisen elementin reunaviivan väri on punainen, joka toisen sininen.

esim. rakenna-divit.php?num=10

# Ohjelmakoodin luku

ThisWeekBlock.php

1. Ohjelmakoodi tuottaa Drupal-lohkon (Blockin), joita käytetään tyypillisesti
sivun osien (kuten valikot, kielivalinta yms.) rakentamiseen. Mitä kyseisellä 
lohkolla rakennetaan, mihin tarkoitukseen se on tehty?
2. Miksi metodissa ThisWeekBlock::build() lisätään cache tag "sivustotehdas:weekly"?
3. Miksi metodissa ThisWeekBlock::build() lisätään cache tag "node:".$node->id()?
4. Mikä syntaksivirhe metodissa ThisWeekBlock::getFieldIlmoittautuminenPaattyyNodes() on?
5. Mikä logiikkavirhe metodissa ThisWeekBlock::getFieldIlmoittautuminenPaattyyNodes() on?

# Tehtävän palautus

Tee GitHubiin oma repository tehtävästä ja lähetä kutsu tälle käyttäjälle (sivustotehdas).

Jos tehtävänannosta on kysyttävää, ota yhteyttä info@sivustotehdas.fi
