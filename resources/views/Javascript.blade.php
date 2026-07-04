<div>
    <ul id="list"></ul> 

    <div id="container3">
        <p id="paragraph">Este é um parágrafo para remover.</p>
    </div>


    <div id="container2"></div>


    <select id="colors">
        <option value="red">Red</option>
        <option value="blue">Blue</option>
        <option value="green">Green</option>
    </select>

    <input type="checkbox" id="subscribe" checked>

    <input type="text" id="username" value="Old Username">

    <a id="link" target = "_blank" href="https://google.com">Visitar Google</a>

    <p id="message">Old message</p>
    <div id="container">
        <h2>Old Heading</h2>
        <p>This is an old paragraph.</p>
    </div>

    <button class="btn primary" id="submit">Submit</button>


    <input type="text" name="username" placeholder="Enter username">
    <input type="password" name="password" placeholder="Enter password">

    <hr>

    <div class="container">This is a container</div>
    <div class="container">This is another container</div>

    <p id="greeting">Hello, World!</p>

    <button onclick = "getclasses()" > Getclasses </button>

    <button onclick = "greeting()">Olá</button>

    <button onclick = "selecionarInput()" >Selecionar</button>

    <button onclick = "getmultiplos()" >Multiplos</button>

    <button onclick = "modificarriner()">Modificar</button>

    <button onclick = "modificarTexto()">Modificar Texto</button>

    <button onclick = "mudarlink()">Mudar link</button>

    <button onclick = "mudarInput()">Mudar Input</button>

    <button onclick = "modificarCheckBox()">Modificar CheckBox</button>

    <button onclick = "modificarSelect()">Modificar Select</button>

    <button onclick = "addElementos()">Adicionar Elementos</button>

    <button onclick = "addLista()">Adicionar Lista</button>

    <button onclick = "remover()">Remover</button>
    
    <script>
        function greeting(){
            //let element = document.getElementById('greeting');
            // element.textContent = 'Welcome to the DOM!';}

            let element = document.querySelector('#greeting');
            element.textContent = 'Welcome to the DOM!';
        }
        function getclasses(){
            //let element = document.querySelector('.container');
            //let cor = element.style.backgroundColor;
            //element.style.backgroundColor = (cor == 'lightblue')?'green':'lightblue';
            
            //Altera todos que tiverem a classe container
            let containers = document.querySelectorAll('.container');

            containers.forEach(container =>{
                let cor = container.style.backgroundColor;
                container.style.backgroundColor = (cor == 'lightblue') ? 'green':'lightblue';
            });
        }

        function selecionarInput(){
            let inputElement = document.querySelector('input[name="username"]');
            let borda = inputElement.style.borderColor;
            let tamanho = inputElement.style.borderWidth 
            inputElement.style.borderColor = borda == 'green' ? 'white': 'green';
            inputElement.style.borderWidth = tamanho == '6px' ? '1px': '6px';
        }

        function getmultiplos(){
            let button = document.querySelector('#submit.primary');
            button.style.color = 'white';
            button.style.backgroundColor = 'blue';
        }    

        function modificarriner(){
                let container = document.getElementById("container");
                container.innerHTML = "<img src='https://images.icon-icons.com/1508/PNG/512/python_104451.png'> " ;
            }
        
        function modificarTexto(){
            let message = document.getElementById("message");
            message.textContent = "This is the new message text!";
        }
        function mudarlink(){
            let link = document.getElementById("link");
            link.setAttribute("href", "https://portal.ifrn.edu.br/");
            //link.setAttribute("target", "_blank");
            link.textContent = "IFRN";
        }

        function mudarInput(){
            let usernameInput = document.getElementById("username");
            usernameInput.value = "New Username";
        }

        function modificarCheckBox(){
            let checkbox = document.getElementById("subscribe");
            let boxstatus = checkbox.checked;
            checkbox.checked = boxstatus?false:true;
        }
        function modificarSelect(){
            let selectElement = document.getElementById("colors");
            selectElement.value = "blue";  // Selects the "Blue" option
        }
        function addElementos(){
            let newParagraph = document.createElement("p");

            // Definir o conteúdo de texto do elemento
            newParagraph.textContent = "Este é um parágrafo criado dinamicamente.";

            // Adicionar o elemento ao container
            let container2 = document.getElementById("container2");
            container2.appendChild(newParagraph);
        }

        function addLista(){
            let listItem = document.createElement("li");
                listItem.textContent = "Item da lista 1";

                // Adicionar o item à lista
                let list = document.getElementById("list");
                list.appendChild(listItem);

                // Usando append() para adicionar outro item com texto
                list.append("Item da lista 2");
        }
        function remover(){
            let container = document.getElementById("container3");
            let paragraph = document.getElementById("paragraph");

            // Remover o parágrafo
            container.removeChild(paragraph);
        }
  
        
    </script>
</div>
