<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bateria</title>
  <link rel="stylesheet" href="bateria.css">
  <link rel="icon" href="https://fav.farm/🔥" />
</head>
<body>
  <div class="keys">
    <div data-key="65" class="key">
      <kbd>A</kbd> <span class="sound">clap</span>
    </div>
    <div data-key="83" class="key">
      <kbd>S</kbd> <span class="sound">hihat</span>
    </div>
    <div data-key="68" class="key">
      <kbd>D</kbd> <span class="sound">kick</span>
    </div>
    <div data-key="70" class="key">
      <kbd>F</kbd> <span class="sound">openhat</span>
    </div>
    <div data-key="71" class="key">
      <kbd>G</kbd> <span class="sound">boom</span>
    </div>
    <div data-key="72" class="key">
      <kbd>H</kbd> <span class="sound">ride</span>
    </div>
    <div data-key="74" class="key">
      <kbd>J</kbd> <span class="sound">snare</span>
    </div>
    <div data-key="75" class="key">
      <kbd>K</kbd> <span class="sound">tom</span>
    </div>
    <div data-key="76" class="key">
      <kbd>L</kbd> <span class="sound">tink</span>
    </div>
    <div data-key="81" class="key">
      <kbd>Q</kbd> <span class="sound">tink</span>
    </div>
  </div>

  <audio data-key="65" src="sounds/clap.wav"></audio>
  <audio data-key="83" src="sounds/hihat.wav"></audio>
  <audio data-key="68" src="sounds/kick.wav"></audio>
  <audio data-key="70" src="sounds/openhat.wav"></audio>
  <audio data-key="71" src="sounds/boom.wav"></audio>
  <audio data-key="72" src="sounds/ride.wav"></audio>
  <audio data-key="74" src="sounds/snare.wav"></audio>
  <audio data-key="75" src="sounds/tom.wav"></audio>
  <audio data-key="76" src="sounds/tink.wav"></audio>
  <audio data-key="81" src="sounds/tink.wav"></audio>

<script>

// Essa função será chamada quando a transição CSS terminar
function removeTransition(e) {
  // O evento "transitionend" pode acontecer para várias propriedades CSS.
  // Aqui queremos agir somente quando a propriedade "transform" terminar a transição.
  // Se a propriedade que terminou NÃO for "transform", a função para aqui.
  if (e.propertyName !== 'transform') return;

  // e.target representa o elemento HTML que sofreu a transição.
  // Aqui removemos a classe "playing" desse elemento,
  // para ele voltar ao visual normal depois da animação.
  e.target.classList.remove('playing');
}

// Essa função será chamada sempre que uma tecla do teclado for pressionada
function playSound(e) {
  // e.keyCode é o código numérico da tecla pressionada.
  // Exemplo: a tecla A tem o código 65.

  // Procura no HTML um elemento <audio> que tenha o mesmo data-key da tecla pressionada.
  // Exemplo: se apertar A, procura audio[data-key="65"].
  const audio = document.querySelector(`audio[data-key="${e.keyCode}"]`);

  // Procura no HTML a <div> visual da tecla pressionada.
  // Exemplo: se apertar A, procura div[data-key="65"].
  const key = document.querySelector(`div[data-key="${e.keyCode}"]`);

  // Se não existir áudio para essa tecla, a função para aqui.
  // Isso evita erro quando o usuário aperta uma tecla que não está cadastrada.
  if (!audio) return;

  // Adiciona a classe "playing" na tecla visual.
  // Essa classe normalmente muda o estilo da tecla no CSS,
  // por exemplo aumentando o tamanho, mudando a borda ou a cor.
  key.classList.add('playing');

  // Faz o áudio voltar para o início.
  // Isso permite tocar o som várias vezes seguidas rapidamente.
  audio.currentTime = 0;

  // Toca o áudio correspondente à tecla pressionada.
  audio.play();
}

// Seleciona todos os elementos que possuem a classe "key"
const keys = Array.from(document.querySelectorAll('.key'));

// Para cada tecla visual encontrada no HTML,
// adiciona um evento que será executado quando a transição CSS terminar.
keys.forEach(key => key.addEventListener('transitionend', removeTransition));

// Adiciona um evento no navegador inteiro.
// Sempre que o usuário pressionar uma tecla, a função playSound será executada.
window.addEventListener('keydown', playSound);
</script>

</body>
</html>