/*
 * Чего нет в старых движках.
 *
 * Сборщик опущен до Safari 12, но переписывает он только ЗАПИСИ: `a?.b`
 * становится проверкой, стрелка — функцией. Методов это не касается —
 * `Object.hasOwn` он оставит как есть, и движок, который о нём не слышал,
 * остановится на нём ошибкой. Разница важная: незнакомая запись — это белый
 * экран (не разобрался весь файл), незнакомый метод — оборванная страница.
 *
 * Здесь дописано ровно то, чем приложение и его зависимости правда пользуются;
 * список сверен по собранному коду, а не взят про запас. Общей библиотеки
 * (core-js) нет намеренно: она весит больше всего приложения и тащит сотни
 * правок, ни одна из которых здесь не нужна.
 *
 * Файл вписывается в саму разметку, впереди приложения, и не может ждать его
 * загрузки: Nuxt разбирает переданное с сервера состояние ещё до первой
 * отрисовки, а разбор этот зовёт `Object.hasOwn`.
 *
 * Написан на ES5 и без единой современной записи: это единственный файл,
 * который никто не переписывает, — он должен читаться тем самым движком,
 * ради которого написан.
 */
(function () {
  'use strict'

  var array = Array.prototype
  var own = Object.prototype.hasOwnProperty

  /**
   * Обход и списка, и любого перечислимого: `Object.fromEntries` принимает
   * не только массив, и чужой код передаёт туда, например, пары из `Map`.
   */
  function forEachOf(source, visit) {
    if (source === null || source === undefined) {
      return
    }

    if (typeof source.length === 'number') {
      array.forEach.call(source, visit)
      return
    }

    var iterator = typeof Symbol === 'function' && source[Symbol.iterator]
      ? source[Symbol.iterator]()
      : null

    if (!iterator) {
      return
    }

    var step = iterator.next()

    while (!step.done) {
      visit(step.value)
      step = iterator.next()
    }
  }

  // Safari 12.0. Nuxt зовёт его, подбирая замену `requestIdleCallback`.
  if (typeof window.globalThis !== 'object') {
    window.globalThis = window
  }

  // Safari 12.0.
  if (!Object.fromEntries) {
    Object.fromEntries = function fromEntries(entries) {
      var result = {}

      forEachOf(entries, function (entry) {
        result[entry[0]] = entry[1]
      })

      return result
    }
  }

  // Safari 15.4. Им поднимается состояние страницы — то есть каждый заход.
  if (!Object.hasOwn) {
    Object.hasOwn = function hasOwn(target, key) {
      return own.call(Object(target), key)
    }
  }

  // Safari 15.4. Берут им последний, `at(-1)`, — ради этого и заведён.
  if (!array.at) {
    array.at = function at(index) {
      var position = Math.trunc(index) || 0

      if (position < 0) {
        position += this.length
      }

      return position < 0 || position >= this.length ? undefined : this[position]
    }
  }

  if (!String.prototype.at) {
    String.prototype.at = function at(index) {
      return array.at.call(this, index)
    }
  }

  // Safari 15.4.
  if (!array.findLast) {
    array.findLast = function findLast(predicate, thisArg) {
      for (var index = this.length - 1; index >= 0; index -= 1) {
        if (predicate.call(thisArg, this[index], index, this)) {
          return this[index]
        }
      }

      return undefined
    }
  }

  /*
   * Safari 13. Разбирает ссылки в сообщениях мессенджера.
   *
   * Настоящий возвращает перечислимое, здесь — готовый список. Разница видна
   * только на бесконечном тексте, которого в сообщении не бывает, а `for…of`
   * и раскрытие в массив работают с ним одинаково.
   */
  if (!String.prototype.matchAll) {
    String.prototype.matchAll = function matchAll(pattern) {
      var flags = pattern.flags.indexOf('g') === -1 ? pattern.flags + 'g' : pattern.flags
      var search = new RegExp(pattern.source, flags)
      var text = String(this)
      var found = []
      var match = search.exec(text)

      while (match !== null) {
        found.push(match)

        // Пустое совпадение не двигает поиск само — иначе он стоял бы на месте.
        if (match[0] === '') {
          search.lastIndex += 1
        }

        match = search.exec(text)
      }

      return found
    }
  }

  // Safari 13. Им грузят страницу разом, не роняя её из-за одного отказа.
  if (!Promise.allSettled) {
    Promise.allSettled = function allSettled(items) {
      var settled = array.map.call(items, function (item) {
        return Promise.resolve(item).then(
          function (value) {
            return { status: 'fulfilled', value: value }
          },
          function (reason) {
            return { status: 'rejected', reason: reason }
          },
        )
      })

      return Promise.all(settled)
    }
  }

  /*
   * Safari 13.1 — и единственная здесь подмена не по правилам.
   *
   * Настоящий следит за размером самих элементов; этот — только за окном, и о
   * выросшем содержимом при неподвижном окне не узнает. Графики и полосы меню,
   * ради которых он заведён, от этого перемеряются реже, чем надо; чат,
   * который так же следит за выросшей перепиской, — прокручивается к новому
   * сообщению с задержкой до следующей перерисовки.
   *
   * Всё это лучше, чем `ResizeObserver is not a constructor` посреди чтения
   * урока: без подмены страница обрывается целиком, а не теряет точность.
   */
  if (typeof window.ResizeObserver !== 'function') {
    window.ResizeObserver = function ResizeObserver(callback) {
      var watched = []
      var observer = this

      function report() {
        callback(
          watched.map(function (target) {
            return { target: target, contentRect: target.getBoundingClientRect() }
          }),
          observer,
        )
      }

      this.observe = function (target) {
        if (watched.indexOf(target) === -1) {
          watched.push(target)
          window.addEventListener('resize', report)
          // Первый отчёт настоящий присылает сразу — на него и рассчитывают.
          window.setTimeout(report, 0)
        }
      }

      this.unobserve = function (target) {
        var position = watched.indexOf(target)

        if (position !== -1) {
          watched.splice(position, 1)
          window.removeEventListener('resize', report)
        }
      }

      this.disconnect = function () {
        while (watched.length > 0) {
          observer.unobserve(watched[0])
        }
      }
    }
  }
})()
